# 25 — Shared AI-call plumbing (DRY for generation options + provider/timeout resolution)

## Summary

One-pass consolidation: extract the **generation-options machinery** (`temperature`/`maxTokens`/`maxSteps`/`topP` setters + resolution + apply) into a reusable trait, and **hoist provider/timeout resolution** (with config-default fallback) up to `SolarisAction`. `HasPromptPipeline` adopts the trait and overrides resolves for preset-awareness; `AiGenerateAction` adopts the trait and inherits the base resolves — gaining `->temperature()` / `->maxTokens()` / `->maxSteps()` / `->topP()` as a side effect of the DRY pass.

```php
// Side effect — now works on AiGenerateAction:
AiGenerateAction::make('seed-articles')
    ->forModel(Article::class)
    ->count(20)
    ->temperature(0.9)      // creativity for diverse seed data
    ->maxTokens(2000)
    ->createRecords();
```

## Motivation

After spec 23, `AiGenerateAction` carries its own `resolveProviderAndModel()` and `resolveTimeout()` — literally stripped-down copies of `HasPromptPipeline`'s. The four generation-options setters aren't yet on `AiGenerateAction`; porting them naively would compound the duplication. The user-visible feature (`->temperature()` on `AiGenerateAction`) and the cleanup are the same change if we pay down the debt now, before a third action arrives.

The 497-test suite (both pipelines have feature coverage) is the regression net.

## Scope (one-pass consolidation)

**In scope:**
- Extract `HasGenerationOptions` trait (the four option setters + simple resolve + apply).
- Hoist `resolveProviderAndModel()` and `resolveTimeout()` (with config-default fallback) onto `SolarisAction`.
- `HasPromptPipeline` adopts the trait and overrides resolves for preset-aware versions.
- `AiGenerateAction` adopts the trait; drops its own provider/timeout resolves (now inherited); applies options to the agent in both execute paths (single-call and per-row loop).

**Explicitly NOT in scope** (intentionally different — sharing would couple):
- Prompt resolution. `HasPromptPipeline` uses prompt builders with the form base-wrapper view; `AiGenerateAction` uses raw `resolveInstructionForRow()` with `$row` injection + `## Current record` context block. These are different by design.
- `createAgent` + `executeAiCall` boilerplate (3–5 lines duplicated). Net DRY win is tiny; leave inline.

## New: `src/Concerns/HasGenerationOptions.php`

A small trait — properties, fluent setters, and the two helpers:

```php
trait HasGenerationOptions
{
    protected float|int|Closure|null $temperature = null;
    protected int|Closure|null $maxTokens = null;
    protected int|Closure|null $maxSteps = null;
    protected float|int|Closure|null $topP = null;

    public function temperature(float|int|Closure|null $temperature): static { … }
    public function maxTokens(int|Closure|null $maxTokens): static { … }
    public function maxSteps(int|Closure|null $maxSteps): static { … }
    public function topP(float|int|Closure|null $topP): static { … }

    /**
     * @return array{temperature: ?float, max_tokens: ?int, max_steps: ?int, top_p: ?float}
     */
    protected function resolveGenerationOptions(): array
    {
        $config = FilamentSolaris::config();

        return [
            'temperature' => $this->evaluate($this->temperature) ?? $config->getDefaultTemperature(),
            'max_tokens'  => $this->evaluate($this->maxTokens)   ?? $config->getDefaultMaxTokens(),
            'max_steps'   => $this->evaluate($this->maxSteps)    ?? $config->getDefaultMaxSteps(),
            'top_p'       => $this->evaluate($this->topP)        ?? $config->getDefaultTopP(),
        ];
    }

    protected function applyGenerationOptions(SolarisAgent $agent): void
    {
        $opts = $this->resolveGenerationOptions();

        $agent
            ->withTemperature($opts['temperature'])
            ->withMaxTokens($opts['max_tokens'])
            ->withMaxSteps($opts['max_steps'])
            ->withTopP($opts['top_p']);
    }
}
```

## Modified: `src/Actions/SolarisAction.php`

Two new `protected` resolution helpers (build on existing `resolveActionProvider()` / `resolveActionTimeout()`):

```php
/**
 * @return array{provider: Lab|array|string|null, model: ?string}
 */
protected function resolveProviderAndModel(): array
{
    $action = $this->resolveActionProvider();

    if ($action !== null) {
        return $action;
    }

    $config = FilamentSolaris::config();

    return ['provider' => $config->getDefaultProvider(), 'model' => $config->getDefaultModel()];
}

protected function resolveTimeout(): ?int
{
    return $this->resolveActionTimeout() ?? FilamentSolaris::config()->getDefaultTimeout();
}
```

Action-only callers (the existing `resolveActionProvider`/`resolveActionTimeout` helpers) stay unchanged.

## Modified: `src/Concerns/HasPromptPipeline.php`

- `use HasGenerationOptions;` (adopt the trait).
- **Remove** the four `pipelineTemperature` / `pipelineMaxTokens` / `pipelineMaxSteps` / `pipelineTopP` properties and their setters (now in the trait, renamed to `temperature` / `maxTokens` / `maxSteps` / `topP` — internal `protected` rename, no public-API impact).
- **Override** `resolveGenerationOptions()` for the preset-aware fallback (action → preset's options → config default → null). PHP trait-method resolution: when an outer trait (`HasPromptPipeline`) defines a method of the same name as an inner trait (`HasGenerationOptions`) it composes, the outer trait's method wins for the using class — so `AiFormAction` gets `HasPromptPipeline`'s preset-aware version automatically. The override is self-contained (inlines the action → preset → config chain) — no `parent::` chaining needed.
- **Override** `resolveProviderAndModel()` for preset-aware fallback (action → preset's provider → config `preset_providers[class]` → config default → null). The current preset-aware logic in `HasPromptPipeline` is preserved verbatim.
- **Override** `resolveTimeout()` for preset-aware fallback.
- Call-site rename: existing `applyOptionsToAgent($agent)` → `applyGenerationOptions($agent)` (rename internal method to match the trait's name; the `runPipeline()` call site updates accordingly).

**Verification**: existing `AiFormAction` option tests (in `tests/Feature/AiFormActionOptionsTest.php`, ~all 4 options + preset interaction) MUST stay green — they're the contract that the preset-aware override didn't regress.

## Modified: `src/Actions/AiGenerateAction.php`

- `use HasGenerationOptions;` — gains the 4 setters and the apply helper.
- **Remove** its own `resolveProviderAndModel()` and `resolveTimeout()` (now inherited from `SolarisAction`).
- In `execute()` (single-call path) and `generateForRow()` (per-row loop path): after `$agent = (new SolarisAgent)->configure(...)`, call `$this->applyGenerationOptions($agent);`.

## Naming

- Trait file: `src/Concerns/HasGenerationOptions.php`.
- Setters: `temperature()`, `maxTokens()`, `maxSteps()`, `topP()` (unchanged from HasPromptPipeline's public API).
- Resolve helper: `resolveGenerationOptions()` (renamed from `resolveOptions()` — clearer scope; the old name is internal-only and not part of any public contract).
- Apply helper: `applyGenerationOptions()` (renamed from `applyOptionsToAgent()` — symmetry with `resolveGenerationOptions()`).
- Internal properties: `temperature`/`maxTokens`/`maxSteps`/`topP` (drop the `pipeline` prefix — they live in the generic options trait now, not in a pipeline-specific class).

## Validation

- No new validation rules. The setters accept `null` to reset; `null` resolution falls through to config default; ultimate fallback is `null` (laravel/ai's default sampling).

## Testing

The 497-test suite is the primary safety net. Targeted additions:

- **`tests/Feature/AiGenerateActionTest.php`** — append:
  - `it('passes generation options to the agent for AiGenerateAction')`: a **unit-style test** — build an `AiGenerateAction` with all 4 setters; instantiate a fresh `SolarisAgent`; invoke `applyGenerationOptions($agent)` (via reflection since it's `protected`); assert the agent's getters (`temperature()`/`maxTokens()`/`maxSteps()`/`topP()`) return the configured values. Simple, deterministic, no fake-pipeline involvement.
  - `it('falls back to the config default for AiGenerateAction generation options when not set')`: set `config('filament-solaris.default_temperature', 0.5)` (or the actual key path), no action setter, assert resolution.
- **No new tests for `HasPromptPipeline`** — its preset-aware behavior is already covered by `AiFormActionOptionsTest`. The override must keep those green.

Refactoring scratchpad: PHPStan L5 will catch property-rename misses. `composer test` after each task gates the broader regression.

## Documentation

- `documentation/ai-generate-action.md`: add a small "Generation options" subsection (mirror AiFormAction's). Update the "v1 limitations" callout from spec 21 — remove the "generation options not available" line.
- `CHANGELOG.md` → `## [Unreleased]` → `### Added`: "`AiGenerateAction` now supports `->temperature()` / `->maxTokens()` / `->maxSteps()` / `->topP()` via the new shared `HasGenerationOptions` trait. Internal refactor: `HasPromptPipeline` and `AiGenerateAction` now share generation-options + provider/timeout resolution machinery."
- `specs/missing-features.md`: trim "Generation options on AiGenerateAction (`temperature` etc.)" from the deferred list.

## Out of scope (deferred)

- **Prompt-resolution sharing** between `HasPromptPipeline` and `AiGenerateAction` — intentionally different shapes (form base-wrapper vs raw + iteration context). Sharing would couple them.
- **`createAgent` + `executeAiCall` boilerplate DRY** — 3–5 lines duplicated; net win negligible.
- **No new generation options** (sampling-temperature variants like `top_k`, frequency/presence penalties, etc.) — out of scope; the four `with*` already mirror laravel/ai's `TextGenerationOptions`.
