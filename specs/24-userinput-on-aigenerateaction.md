# 24 — UserInput on `AiGenerateAction`

## Summary

Add `UserInput`-modal support to `AiGenerateAction`, mirroring the pattern shipped on `AiFormAction` and `DictationFieldAction`. Modal-collected values:

1. **Auto-inject into the prompt** as a `## User context` JSON block (top-level and per-row in the records loop, alongside the existing `## Current record` block).
2. **Are available as a `$userInput` closure named-arg** in `->prompt()`, `->handleUsing()`, and `->sourceRecords()`.

The concrete unlock is the matrix-cell from spec 23 (paste/upload → `->updateRecords()`), but the same machinery serves one-shot generation steered by free-text input and CSV-driven imports.

## Motivation

`AiGenerateAction` shipped (spec 21) intentionally without `UserInput` — `hasUserInput()` was hardcoded `false`. That kept the v1 surface small.

The matrix from spec 23 left one cell empty by design:

| | no `->sourceRecords()` | with `->sourceRecords()` |
|---|---|---|
| `->updateRecords()` | **deferred → this spec** | enrich (shipped in 23) |

"Paste a spreadsheet of `(id, payload)` rows and enrich each by id" is a real workflow. Today the consumer rolls a custom Livewire form to collect the file/paste, parse it, and call the action programmatically. With `UserInput` on `AiGenerateAction`, the user's `->sourceRecords()` closure reads from the modal directly — no bespoke form.

A second motivation surfaced during design: a `Textarea` in the modal collecting per-batch steering text ("focus on SEO and conversational tone") is a clean way to vary a prompt without redeploying. Today there's no place to put that text without writing a custom modal.

## Scope

In:
- New: `src/Concerns/HasDefaultUserInput.php` (the `withDefaultUserInput()` + preset-default-input logic, extracted from today's `HasUserInput`).
- Modified: `src/Concerns/HasUserInput.php` (slimmed to setter + `hasUserInput`/`getUserInput`/`getUserInputFormSchema`).
- Modified: `src/Concerns/HasPromptPipeline.php` (adopt both traits — preserves today's `AiFormAction` behaviour byte-for-byte).
- Modified: `src/Actions/AiGenerateAction.php` (adopt `HasUserInput` only; thread `$userInput` through every execute path; auto-inject the `## User context` block; add closure-DI named-arg).
- Modified: `src/Testing/AiGenerateActionFake.php` (`recordCall` gains a third `array $userInput = []` arg + `assertCalledWithUserInput()` helper).
- Tests: new `tests/Feature/AiGenerateActionUserInputTest.php` + minor fixture additions in `GenerateFormComponent`.
- Docs: `documentation/ai-generate-action.md` gains a "User Input" section.

Out:
- Attachments (sending UserInput-modal files to the AI as `File` objects). **Tracked as the immediate follow-up spec** — see "Deferred / next" below.
- Presets on `AiGenerateAction` (the trait split keeps the door open; not adopted in this spec).
- `outputSchema()` closure-DI for `$userInput` (edge case; deferred until a concrete need surfaces).
- Excel parsing as a core capability (push to userland: Laravel-Excel / phpoffice).
- Resumable / multi-step modals.

## Trait split (Option C from brainstorming)

The current `HasUserInput` carries `withDefaultUserInput()`, which reads `$this->promptBuilder` to pull a preset's default-user-input. `AiGenerateAction` has no `$promptBuilder` (no presets in v1), so the method is meaningless there.

**Split:**

- **`HasUserInput`** (slim): `protected $userInput`, `->userInput()`, `hasUserInput()`, `getUserInput()`, `getUserInputFormSchema()`.
  - `getUserInput()` returns the explicit setter value if present; otherwise calls `$this->getDefaultUserInputFromBuilder()` **only if** that method exists (`method_exists($this, 'getDefaultUserInputFromBuilder')`); otherwise null.
- **`HasDefaultUserInput`** (new): `$useDefaultUserInput` flag, `->withDefaultUserInput()`, `protected getDefaultUserInputFromBuilder(): ?UserInput` (reads `$this->promptBuilder->defaultUserInput()` when both the flag and the property are set).

**Composition:**
- `HasPromptPipeline` uses **both** traits — `AiFormAction` behaviour byte-for-byte unchanged.
- `AiGenerateAction` uses **only `HasUserInput`** — calling `->withDefaultUserInput()` on it raises `BadMethodCallException` (loud failure, no silent footgun).

`method_exists()` glue chosen over a formal `HasDefaultUserInput` interface for v1: one line vs an interface + import, identical behaviour. Revisit if a third consumer appears.

## Modified: `src/Actions/AiGenerateAction.php`

**Imports:**
- Add `use Statikbe\FilamentSolaris\Concerns\HasUserInput;`.

**Class body:**
- Add `use HasUserInput;` (next to any existing `use TraitName;` block — see `HasGenerationOptions` adoption from spec 25 for the pattern).
- Delete the existing `public function hasUserInput(): bool { return false; }` (line ~415). The trait's default takes over.

**`setUp()` changes:**
- Add `$this->schema(fn (AiGenerateAction $action): array => $action->getUserInputFormSchema());` — mirrors `AiFormAction:50-56`. When no `->userInput()` is set, returns `[]`; Filament v4 then skips the modal and runs `action()` directly. (Verified during impl — fall back to explicit `modalSubmitAction(false)` if that turns out to not be the case.)
- Update the `action()` callback signature: `function (AiGenerateAction $action, array $data = [])` → `$action->execute($data)`.

**`execute()` signature change:**
- `public function execute(array $data = []): void` — `$data` is the modal form values (treated as `$userInput` from here down).
- All defaults `[]` so existing test/code paths that call `execute()` with no args keep working.

**Thread `$userInput` through every execute path:**

| Method (today) | After spec 24 |
|---|---|
| `execute()` | `execute(array $data = [])` — passes `$data` as `$userInput` down |
| `executeRecordsLoop()` | `executeRecordsLoop(array $userInput)` |
| `executeFake()` | `executeFake(array $userInput)` |
| `dispatchSingleResponse(array $data)` | `dispatchSingleResponse(array $data, array $userInput)` |
| `resolveInstruction()` | `resolveInstruction(array $userInput)` |
| `resolveInstructionForRow($row)` | `resolveInstructionForRow($row, array $userInput)` |
| `generateForRow($row, ...)` | `generateForRow($row, ..., array $userInput)` |
| `resolveRecordsSource()` | `resolveRecordsSource(array $userInput)` |

**Closure DI named-arg `$userInput`** added to every `$this->evaluate($closure, [...])` call site that reaches a user-supplied closure:

- `resolveInstruction()` → `evaluate($this->instruction, ['userInput' => $userInput])`.
- `resolveInstructionForRow($row, $userInput)` → `evaluate($this->instruction, ['row' => ..., 'userInput' => $userInput])`.
- `dispatchSingleResponse($data, $userInput)` → `evaluate($this->handler, ['data' => $data, 'records' => ..., 'userInput' => $userInput])`.
- `resolveRecordsSource($userInput)` → when `$this->source instanceof Closure`: `evaluate($this->source, ['userInput' => $userInput])`.

Terminals (`->createRecords()`, `->updateRecords()`) are not closures — they don't receive `$userInput`.

**Auto-inject `## User context` block:**

In both `resolveInstruction($userInput)` and `resolveInstructionForRow($row, $userInput)`, after the instruction closure is evaluated and `View` rendered, append:

```php
$filtered = array_filter($userInput, static fn ($v): bool => filled($v));
if ($filtered !== []) {
    $json = json_encode($filtered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $instruction = trim($instruction)."\n\n## User context\n```json\n{$json}\n```";
}
```

`array_filter(filled)` strips empty Textareas / unfilled fields so the block doesn't leak `"foo": null` noise to the model. Block omitted entirely when nothing is filled.

**Ordering in `resolveInstructionForRow`:** prompt → `## User context` → `## Current record`. (Persistent batch frame before per-row data.)

## Modified: `src/Testing/AiGenerateActionFake.php`

**`recordCall` signature change:**
```php
public function recordCall(string $actionName, array $data, array $userInput = []): void
```
Stored alongside the existing `name` / `data` entry; default `[]` keeps existing tests passing without changes.

**New helper:** `assertCalledWithUserInput(Closure $callback): void` — mirrors `assertHandledWith` style. Symmetry with `AiFormAction` reads via positional `$options` in closures, but explicit DX wins here: the helper makes the assertion intent obvious at the call site.

## Public API surface (cheat sheet)

```php
AiGenerateAction::make('enrich-from-spreadsheet')
    ->userInput(UserInput::make()->fields([
        FileUpload::make('csv')->acceptedFileTypes(['text/csv'])->required(),
        Textarea::make('focus')->placeholder('Tone, style, audience…'),
    ]))
    ->forModel(Article::class)
    ->prompt('Enrich each article for the requested audience.')
    ->sourceRecords(fn (array $userInput) => Article::query()
        ->whereIn('id', collect(parseCsv($userInput['csv']))->pluck('id'))
        ->get())
    ->updateRecords();
```

What the model receives per row (synthesised):

```markdown
Enrich each article for the requested audience.

## User context
```json
{
    "csv": "uploads/abc.csv",
    "focus": "SEO and conversational tone"
}
```

## Current record
```json
{
    "id": 42,
    "title": "...",
    "body": "..."
}
```
```

(Note the `csv` path-string in the User context block is mostly harmless to the model — users wanting to strip it from the auto-injected context can build their `UserInput` schema without the file field, or filter via a wrapper closure in their `->prompt()`.)

## Closure-DI subtleties

- All four closure types receive `$userInput` as a Filament-style named arg. Resolved through `$this->evaluate($closure, [...])` — Filament's reflection picks up the parameter name.
- `->prompt(fn ($userInput) => ...)` and `->prompt(fn ($row, $userInput) => ...)` both work. Parameter order is irrelevant; named-arg matching is what counts.
- A closure that omits `$userInput` is still valid (Filament's `evaluate()` only injects parameters the closure declares). Backward-compatible with existing closures.

## Validation

- Filament's standard form validation on the `UserInput` fields runs before `execute($data)` is called. Required-field errors, file-size errors, etc. surface in the modal.
- User-closure throws (`->sourceRecords` parse error, `->prompt` template error) propagate to Filament's error handler — same as today's behaviour for the existing closure-receiving methods. **Not adding** a custom try/catch around `resolveRecordsSource` in v1. If real users hit raw stack traces, add a targeted wrap in a follow-up. Document the recommended pattern (user catches in their closure and shows a clear notification themselves).
- `->handleUsing` closure throws are caught by the existing `dispatchSingleResponse` try/catch — unchanged.
- Empty modal submit → `$userInput` is `[]` or all-null. `array_filter(filled)` means the auto-inject block is just omitted. Closures still receive an empty array — user code's responsibility from there.

## Testing

**New file: `tests/Feature/AiGenerateActionUserInputTest.php`** (~10 tests):

1. `->userInput()` configures the modal: `hasUserInput()` true; `getUserInputFormSchema()` returns the textarea.
2. No `->userInput()` → action runs directly (no modal opens); `hasUserInput()` false. Re-uses existing fixture flavours.
3. `$userInput` reaches `->prompt()` closure (single-call path): action with `->prompt(fn ($userInput) => ...)` + `AiGenerateAction::fake()`; call with `data: ['focus' => 'SEO']`; assert the closure received `'SEO'` via a recorded sentinel.
4. `$userInput` reaches `->handleUsing()` closure: `assertHandledWith(fn ($data, $userInput) => expect($userInput['focus'])->toBe('SEO'))`.
5. `$userInput` reaches `->sourceRecords()` closure: action with `->sourceRecords(fn ($userInput) => [...])` returning rows derived from `$userInput`; assert the loop processed the expected rows.
6. `$userInput` reaches per-row `->prompt()` in the loop: `AiGenerateAction::fakeEach([...])` + `->prompt(fn ($row, $userInput) => ...)`; assert per-row instructions captured `$userInput` values.
7. Auto-inject `## User context` block in single-call instruction: reflection on `resolveInstruction(['focus' => 'SEO'])`; assert returned string contains `## User context` and `"focus": "SEO"`.
8. Auto-inject block in per-row instruction: same shape via `resolveInstructionForRow($row, ['focus' => 'SEO'])`; assert order is prompt → User context → Current record.
9. Block omitted when `$userInput` is empty or all-null: `resolveInstruction([])` and `resolveInstruction(['x' => null, 'y' => ''])` both return prompt with NO `## User context` section.
10. `->withDefaultUserInput()` throws `BadMethodCallException` on `AiGenerateAction` (no method on the slim trait).

**Modified existing tests:** one or two `AiGenerateActionTest.php` cases that already exercise `recordCall` should add a positive assertion that `$userInput` is captured (even if empty); the third arg's default `[]` keeps the rest passing.

**Safety-net regression:** `AiFormAction`'s existing UserInput coverage (`tests/Feature/AiFormActionUserInputTest.php` if present, or wherever `withDefaultUserInput()` is exercised) must stay green after the trait split — that's the proof the extraction was behaviour-preserving.

**Fixtures:** add 3–4 new methods to `tests/Fixtures/GenerateFormComponent.php` to host configured actions for the new tests. Mirror the existing fixture pattern (one method per scenario).

## Documentation

`documentation/ai-generate-action.md` gains a new section after "Provider, Model & Timeout" and before "Generation Options":

```
## User Input

Open a Filament modal before the action runs to collect runtime values
(steering text, file uploads, structured selections). Modal data is:

1. Auto-injected into the prompt as a `## User context` JSON block (top-level
   and per-row in the records loop, alongside `## Current record`).
2. Available as a `$userInput` named arg in `->prompt()`, `->handleUsing()`,
   and `->sourceRecords()` closures.

[example covering the spreadsheet-enrich use case]

[example covering the Textarea-steering use case]

[note on file uploads in the modal: paths surface as strings in `$userInput`;
attachment-on-AI-call support is a separate spec — see "Deferred / next"]
```

CHANGELOG entry under `## [Unreleased] > ### Added`.

## Deferred / next (priority order)

1. **Attachments on `AiGenerateAction`** — flagged as the immediate next priority. Adopt `HasAttachments`, send UserInput-modal `FileUpload` fields to the AI as `File` attachments (Image/Audio/Document, MIME-detected). User: "makes it much more usable." Reuses the proven pattern from `AiFormAction` + `ImageGenerationAction`. Own spec.
2. **Presets on `AiGenerateAction`** — the trait split keeps the door open. When picked up, will need to extend the existing `Preset` (prompt-builder shape) to also bundle action-config (terminal, `forModel`, `columnHints`). Own spec; pick up only when a concrete reusable preset shape surfaces (`TaxonomyPreset`? `SeoMetaPreset`?), not for the thin `SeederPreset` / `EnricherPreset` shapes.
3. `->outputSchema(fn ($schema, $userInput) => ...)` and `->count(fn ($userInput) => ...)` closure-DI — both are user-supplied closures, both are edge cases for `$userInput` injection. Deferred until a concrete consumer asks.
4. Pre-run validation UX for `->sourceRecords()` parse errors (custom try/catch + modal-side error surface) — only if real users hit it.

## Related

- `specs/10-user-input.md` — `UserInput` value object + the original modal pattern on `AiFormAction`.
- `specs/21-ai-generate-action.md` — `AiGenerateAction` v1 (intentionally without UserInput).
- `specs/23-record-writeback-enrichment.md` — the per-record loop primitive this composes with.
- `src/Concerns/HasUserInput.php` / `src/Support/UserInput.php` — the trait being split.
- `src/Actions/AiFormAction.php` — the existing UserInput+modal wiring pattern this mirrors.
