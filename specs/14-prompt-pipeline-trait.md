# 14 — HasPromptPipeline Trait

## Summary

`HasPromptPipeline` is a trait extracted from `AiAction` that contains the shared AI execution pipeline: prompt/preset configuration, AI call, result transformation, result application, and notifications. Both `AiAction` and `DictationAction` use this trait to avoid duplicating the core pipeline logic. The trait expects the consuming class to provide source data as an array — it does not care where the data comes from (form fields, audio transcript, or any other source).

## Trait: HasPromptPipeline

### Traits Used (composed)
- `HasTargetFields`
- `HasUserInput`

### Properties

- `$promptBuilder` (?PromptBuilder): The prompt builder instance. Set via `->prompt()`, `->preset()`, or `->promptBuilder()`.
- `$promptInstruction` (?string): The inline instruction string, when using `InlinePromptBuilder`.
- `$localeOverride` (string|Closure|null): Override locale. Defaults to `app()->getLocale()`.
- `$pipelineProvider` (Lab|array|string|Closure|null): AI provider override. Supports failover arrays and closures.
- `$pipelineModel` (string|Closure|null): AI model override.

### Configuration Methods

#### `prompt(string|View $instruction): static`
Sets an inline string instruction (creates `InlinePromptBuilder`) or a Blade view (creates `ViewPromptBuilder`).

#### `preset(Preset $preset): static`
Sets a Preset as the PromptBuilder. The preset itself implements `PromptBuilder`.

#### `promptBuilder(PromptBuilder $builder): static`
Sets a custom PromptBuilder instance directly. Escape hatch for advanced use cases.

#### `locale(string|Closure $locale): static`
Overrides the locale for this action. Affects the locale hint in the prompt.

#### `getLocale(): string`
Resolves the locale: evaluates closure if needed, falls back to `app()->getLocale()`.

#### `provider(Lab|array|string|Closure $provider, string|Closure|null $model = null): static`
Sets the AI provider (and optionally model) for this action. Supports failover arrays (`['openai' => 'gpt-4o', 'anthropic']`) and closures (Filament convention).

```php
->provider('anthropic', 'claude-sonnet-4-5-20250514')
->provider(['openai' => 'gpt-4o', 'anthropic'])
->provider(fn () => config('my-app.ai_provider'))
```

### Pipeline Methods

#### `runPipeline(array $sourceData, array $userInput): void`
The core execution method. Receives source data (from any origin) and user input, then:

1. Resolves target factories via `resolveTargetFactories()`
2. Resolves the current record (if available)
3. Builds the prompt via the configured PromptBuilder
4. Logs the prompt if enabled
5. Resolves provider/model via `resolveProviderAndModel()`
6. Calls the AI via `SolarisAgent`, passing the resolved provider and model to `$agent->prompt($prompt, [], $provider, $model)`
7. Applies results to the form

```php
protected function runPipeline(array $sourceData, array $userInput): void
```

#### `runFakePipeline(array $sourceData, array $userInput): void`
Handles the fake path for testing. Resolves provider/model, records the call (including provider/model) on `AiActionFake`, checks for error/timeout simulation, and applies fake responses.

```php
protected function runFakePipeline(array $sourceData, array $userInput): void
```

#### `resolveProviderAndModel(): array`
Resolves the provider and model to use for the AI call. Returns `['provider' => ..., 'model' => ...]`.

**Resolution chain (highest to lowest priority):**
1. Action-level `->provider()` (evaluates closure if needed)
2. Preset-level `->provider()` (if the PromptBuilder is a Preset)
3. Config `preset_providers[PresetClass::class]` (if the PromptBuilder is a Preset)
4. Config `default_provider` / `default_model`
5. `null` — laravel/ai uses its own default (`config('ai.default')`)

```php
protected function resolveProviderAndModel(): array
```

#### `applyResults(array $aiResponse, array $factories): void`
Transforms AI response values via factories and writes them to form fields. Sends appropriate notifications (success, partial failure, full failure).

```php
protected function applyResults(array $aiResponse, array $factories): void
```

#### `resolveRecord(): ?Model`
Attempts to get the current Eloquent model from the Livewire component.

#### `resolveFormSchemaComponent(): ?Component`
Resolves a form schema component for Get/Set utilities.

### Helper Methods

#### `resolveFieldLabel(string $fieldName): string`
Resolves a human-readable label for a field name from the form schema.

#### `formatFieldList(array $labels): string`
Wraps labels in quotes and joins with commas and "&".

#### `validatePipelineConfiguration(): void`
Validates that a PromptBuilder is configured and target fields are set. Throws `RuntimeException` on invalid configuration.

### Behavior

#### Given runPipeline() is called with source data
- When a PromptBuilder is configured
- Then the prompt is built with the source data as-is
- And the resolved provider/model are passed to the AI call
- And results are applied

#### Given runPipeline() is called and AiActionFake is active
- When any action executes
- Then `runFakePipeline()` is called instead
- And no real AI call is made

#### Given the AI returns a partial response
- When some fields have valid values and others are null or missing
- Then valid fields are applied to the form
- And failed fields trigger a warning notification

#### Given the AI call throws a RateLimitedException
- When the pipeline catches the error
- Then a danger notification is shown
- And no form fields are modified

## Extraction from AiAction

The following methods move from `AiAction` to `HasPromptPipeline`:

| Method | Currently in | Moves to |
|---|---|---|
| `prompt()` | AiAction | HasPromptPipeline |
| `preset()` | AiAction | HasPromptPipeline |
| `promptBuilder()` | AiAction | HasPromptPipeline |
| `locale()` | AiAction | HasPromptPipeline |
| `applyResults()` | AiAction (private) | HasPromptPipeline (protected) |
| `resolveRecord()` | AiAction (private) | HasPromptPipeline (protected) |
| `resolveFormSchemaComponent()` | AiAction (public) | HasPromptPipeline (public) |
| `resolveFieldLabel()` | AiAction (private) | HasPromptPipeline (protected) |
| `formatFieldList()` | AiAction (private) | HasPromptPipeline (protected) |

AiAction retains:
- `HasSourceFields` trait (not shared)
- `execute()` method (calls `runPipeline()` with form field data)
- `validateConfiguration()` (adds sourceFields check on top of pipeline validation)
- Static fake/assert methods (delegate to `AiActionFake`)

## Impact on AiAction

After extraction, `AiAction` becomes a thin orchestrator:

```php
class AiAction extends Action
{
    use HasSourceFields;
    use HasPromptPipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->icon(app(FilamentSolarisConfig::class)->getActionIcon());
        // ... modal configuration, same as before
        $this->action(function (AiAction $action, array $data = []) {
            $action->execute($data);
        });
    }

    public function execute(array $data = []): void
    {
        $this->validateConfiguration();

        if (AiActionFake::isActive()) {
            $this->runFakePipeline($this->getSourceFieldValues(), $data);
            return;
        }

        $sourceData = $this->getSourceFieldValues();

        if (! collect($sourceData)->contains(fn ($v) => filled($v))) {
            // ... empty source notification
            return;
        }

        $this->runPipeline($sourceData, $data);
    }
}
```

## Testing Impact

All existing tests remain valid. The extraction is a pure refactor — no behavior changes. `AiAction::fake()` and all assertion methods stay on `AiAction` as static convenience methods.
