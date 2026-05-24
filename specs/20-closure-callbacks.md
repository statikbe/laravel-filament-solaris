# 20 — Closure callbacks for `prompt` / `sourceFields` / `targetFields`

## Summary

Add `Closure` support — resolved through Filament's `$this->evaluate()`, so closures receive Filament's standard dependency injection — to three action-level setters:

- `->prompt()`
- `->sourceFields()`
- `->targetFields()`

Closures get Filament's injected args (`$record`, `$livewire`, `$component`, `$get`, `$operation`, …). The `->prompt()` closure **additionally** receives the pipeline context — `$sourceData`, `$userInput`, `$locale` — as named args, since those are available when the prompt is built.

This lets consumers shape the prompt and the source/target field sets from the current record (and, for the prompt, the gathered source values):

```php
AiAction::make('summarize')
    ->sourceFields(fn ($record) => $record->translatable_columns)
    ->targetField('summary')
    ->prompt(fn ($record, $sourceData) =>
        "Summarise '{$sourceData['body']}' for a {$record->audience} audience.");
```

**Out of scope:** preset setter closures (presets are not Filament components and have no component context — they'd need a separate injection mechanism) and `UserInput` closures.

## Motivation

Today `->prompt()` accepts only `string|View`, and `->sourceFields()` / `->targetFields()` accept a `Closure` but resolve it with `value()` (zero-arg, no injection). Consumers frequently need the Eloquent record while composing a prompt or deciding which fields to read/write — exactly the dependency injection Filament already provides to its own action/closure callbacks. This brings Solaris's closures in line with that expectation.

## Design

### `sourceFields()` / `targetFields()`

Both backing properties are already typed `array|Closure`; only the resolution getters use `value()`. Switch them to `$this->evaluate()` (which returns non-closures unchanged and injects dependencies into closures):

- `HasSourceFields::getSourceFields()` — `value($this->sourceFieldNames)` → `$this->evaluate($this->sourceFieldNames)`
- `HasTargetFields::getTargetFields()` — `value($this->targetFieldNames)` → `$this->evaluate($this->targetFieldNames)`
- `DictationFieldAction::getTargetFields()` — the explicit-override branch reads `value($this->targetFieldNames)` directly (because `HasTargetFields` is mixed in via `HasPromptPipeline`); change to `$this->evaluate($this->targetFieldNames)`

```php
->sourceFields(fn ($record) => $record->isPremium() ? ['title', 'body', 'notes'] : ['title', 'body'])
->targetFields(fn ($record) => $record->translatable_columns)
```

**Injection set:** Filament defaults only. Source/target fields are resolved *before* source values are gathered and before the AI call, so the pipeline context (`$sourceData`) is not available here and is not injected.

**`targetField()` (singular adder) is unchanged.** It reads the current value at set-time to append (`$resolved = value($this->targetFieldNames); $resolved[] = $field;`). Mixing the incremental `->targetField('x')` adder with a closure passed to `->targetFields(fn …)` is unsupported — use one or the other. (Documented; not guarded in code.)

### `prompt()`

`->prompt()` currently selects the prompt builder at set-time from the argument type (`View` → `ViewPromptBuilder`, otherwise `InlinePromptBuilder`). A closure's return type isn't known until evaluation, so builder selection for the closure case defers to build time.

1. Widen the signature: `prompt(string|View|Closure $instruction): static`.
2. Extract the type→builder mapping into a private helper:

   ```php
   private function resolvePromptBuilderFor(string|View $instruction): PromptBuilder
   {
       return $instruction instanceof View ? new ViewPromptBuilder : new InlinePromptBuilder;
   }
   ```

3. Setter behaviour:
   - **Not a closure** (current behaviour preserved): set `$this->promptBuilder = $this->resolvePromptBuilderFor($instruction)` and store the instruction.
   - **Closure**: store the closure in `$this->promptInstruction`; leave `$this->promptBuilder` unset (null) — it's chosen at build time.

4. `buildPrompt()`: before using the instruction, resolve a closure and pick the builder from its result:

   ```php
   $instruction = $this->promptInstruction ?? '';

   if ($instruction instanceof Closure) {
       $instruction = $this->evaluate($instruction, [
           'sourceData' => $sourceData,
           'userInput' => $userInput,
           'locale' => $locale,
       ]);

       $this->promptBuilder ??= $this->resolvePromptBuilderFor(
           $instruction instanceof View ? $instruction : (string) $instruction
       );
   }
   ```

   Then continue as today: `$this->promptBuilder->build($instruction, $sourceData, $factories, $record, $locale, $userInput)`.

**Closure contract:** the closure must return a `string` or a `View`. A `View` result selects `ViewPromptBuilder`; anything else is cast to `string` and uses `InlinePromptBuilder`.

**Injection set:** Filament defaults **plus** `$sourceData`, `$userInput`, `$locale` (named). `$record` comes from Filament's own record resolution for the action.

### Cross-cutting

- **`$record` is `null` on Create pages** (the model doesn't exist yet) for all three setters. Closures must tolerate a null record. This is a documented caveat, not an error.
- **`$this->evaluate()` availability:** all three resolution points live on `SolarisAction` subclasses (the source/target traits are composed via `HasPromptPipeline` into `AiAction` / `ImageGenerationAction` / `DictationFieldAction`), so `evaluate()` is always present.
- **Backwards compatible:** arrays/strings/Views passed today resolve identically (`evaluate()` returns non-closures as-is; the prompt setter keeps its set-time builder selection for non-closures).

## Testing

Feature tests (reuse existing fixtures + `AiActionFake`):

- **prompt closure receives `$record`** → its returned string is used as the AI prompt instruction (assert via `AiAction::assertCalledWith(fn ($sourceData, $prompt) => …)`).
- **prompt closure receives `$sourceData`** → can reference gathered source values in the instruction.
- **prompt closure returning a `View`** → `ViewPromptBuilder` is used (assert the rendered prompt reflects the view).
- **`sourceFields` closure receives `$record`** → resolves the field list → those source values are gathered.
- **`targetFields` closure receives `$record`** → resolves targets → the AI fills exactly those fields.
- **Regression:** non-closure `prompt` (string and `View`), `sourceFields` (array), `targetFields` (array) behave exactly as before.

Gates: PHPStan level 5 (`[OK]`), Pint, full Pest suite.

## Documentation

- `documentation/ai-action.md`: a "Dynamic with closures" subsection covering closures on the three setters, the injected args (`$record` + Filament defaults; `$sourceData`/`$userInput`/`$locale` for prompt), and the Create-page null-`$record` caveat.
- `README.md`: a short note/example in the relevant recipe.
- `CHANGELOG.md`: `Added` entry under `Unreleased`.

## Out of scope / future

- **Preset setter closures** (`->maxWords()`, `->tone()`, `->language()`, …). Presets lack a Filament component context, so injecting `$record` there needs a dedicated, fixed-set evaluator on the prompt-builder base — deliberately deferred.
- **`UserInput` closures** (`->prompt()` / `->placeholder()` / `->fields()` resolve via `value()`).
