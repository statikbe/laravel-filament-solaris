# Shared AI-call Plumbing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract generation-options machinery into a shared `HasGenerationOptions` trait and hoist provider/timeout resolution onto `SolarisAction`, so `HasPromptPipeline` and `AiGenerateAction` stop duplicating it — and `AiGenerateAction` gains `->temperature()` / `->maxTokens()` / `->maxSteps()` / `->topP()` as a side effect.

**Architecture:** New `Concerns\HasGenerationOptions` trait owns the four option properties, fluent setters, a simple resolve (action override → config default → `null`), and an `applyGenerationOptions(SolarisAgent)` helper. `SolarisAction` gains base `resolveProviderAndModel()` / `resolveTimeout()` that fall back from the action-level helpers to config defaults. `HasPromptPipeline` adopts the trait and **overrides** both resolves + `resolveGenerationOptions()` to preserve preset-aware fallback (PHP trait method resolution: outer class wins over inner trait). `AiGenerateAction` adopts the trait and inherits the base resolves.

**Tech Stack:** PHP 8.3, Filament v4, laravel/ai v0.6.4+, Pest, PHPStan L5, Pint. Spec: `specs/25-shared-ai-call-plumbing.md` (already committed `83e6ea9` on branch `feature/shared-ai-call-plumbing`).

---

## File Structure

**New:**
- `src/Concerns/HasGenerationOptions.php` — 4 properties + 4 setters + `resolveGenerationOptions()` + `applyGenerationOptions(SolarisAgent)`. ~75 lines.

**Modified:**
- `src/Actions/SolarisAction.php` — add base `resolveProviderAndModel()` / `resolveTimeout()` (build on existing `resolveActionProvider()` / `resolveActionTimeout()`).
- `src/Concerns/HasPromptPipeline.php` — adopt trait; delete the four `pipeline*` properties and their setters; rename internal `resolveOptions` → `resolveGenerationOptions` and `applyOptionsToAgent` → `applyGenerationOptions` (PhpStorm MCP); override the three resolves for preset-aware fallback; update `runPipeline()` call site.
- `src/Actions/AiGenerateAction.php` — adopt trait; delete own `resolveProviderAndModel()` and `resolveTimeout()` (now inherited from `SolarisAction`); call `$this->applyGenerationOptions($agent)` after `(new SolarisAgent)->configure(...)` in both `execute()` and `generateForRow()`.
- `tests/Feature/AiGenerateActionTest.php` — append two new tests (set-and-apply, config fallback).
- `documentation/ai-generate-action.md` — new "Generation options" section; trim "v1 limitations" callout (remove generation-options line; keep preview/conversational line).
- `CHANGELOG.md` — `## [Unreleased] > ### Added` entry.
- `specs/missing-features.md` — trim the "Generation options on AiGenerateAction" follow-up bullet.

**Branch state at start:** `feature/shared-ai-call-plumbing` already exists with the spec committed (`83e6ea9`). Confirm `git status` is clean and tests pass before Task 1.

---

### Task 0: Sanity check

**Files:** none modified.

- [ ] **Step 1: Verify branch + clean tree**

```bash
git -C /Users/sten/PhpStormProjects/laravel-filament-solaris branch --show-current
git -C /Users/sten/PhpStormProjects/laravel-filament-solaris status --porcelain
```

Expected: `feature/shared-ai-call-plumbing`, no porcelain output (clean).

- [ ] **Step 2: Baseline green**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && composer test
```

Expected: 497 tests pass (this is the regression net for the whole plan).

- [ ] **Step 3: Baseline PHPStan**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && composer phpstan
```

Expected: `[OK] No errors`.

---

### Task 1: Create `HasGenerationOptions` trait

**Files:**
- Create: `src/Concerns/HasGenerationOptions.php`

The trait stands alone — no consumer wiring yet. We don't write a standalone test for it because it relies on Filament Action's `$this->evaluate()` (which only exists when the trait is used on an Action subclass). The integration tests in Task 4 are the lock-in.

- [ ] **Step 1: Write the trait**

```php
<?php

declare(strict_types=1);

namespace Statikbe\FilamentSolaris\Concerns;

use Closure;
use Statikbe\FilamentSolaris\FilamentSolaris;
use Statikbe\FilamentSolaris\Support\SolarisAgent;

/**
 * Shared text-generation option setters + resolution + application.
 *
 * Used by {@see HasPromptPipeline} (which overrides resolveGenerationOptions()
 * for preset-aware fallback) and by {@see \Statikbe\FilamentSolaris\Actions\AiGenerateAction}.
 *
 * Setters accept Closure for runtime resolution via Filament's evaluate().
 * The base resolveGenerationOptions() falls back action → config default → null
 * (where null lets laravel/ai use its own #[Temperature] / provider defaults).
 */
trait HasGenerationOptions
{
    protected float|int|Closure|null $temperature = null;

    protected int|Closure|null $maxTokens = null;

    protected int|Closure|null $maxSteps = null;

    protected float|int|Closure|null $topP = null;

    public function temperature(float|int|Closure|null $temperature): static
    {
        $this->temperature = $temperature;

        return $this;
    }

    public function maxTokens(int|Closure|null $maxTokens): static
    {
        $this->maxTokens = $maxTokens;

        return $this;
    }

    public function maxSteps(int|Closure|null $maxSteps): static
    {
        $this->maxSteps = $maxSteps;

        return $this;
    }

    public function topP(float|int|Closure|null $topP): static
    {
        $this->topP = $topP;

        return $this;
    }

    /**
     * Resolve text-generation options for the AI call.
     *
     * Default chain per option (highest to lowest):
     * 1. Action setter
     * 2. Config default_{temperature|max_tokens|max_steps|top_p}
     * 3. null (laravel/ai falls back to its own attribute defaults)
     *
     * Consumers with richer chains (e.g. preset-aware) override this.
     *
     * @return array{temperature: ?float, max_tokens: ?int, max_steps: ?int, top_p: ?float}
     */
    protected function resolveGenerationOptions(): array
    {
        $config = FilamentSolaris::config();

        $temperature = $this->evaluate($this->temperature) ?? $config->getDefaultTemperature();
        $maxTokens = $this->evaluate($this->maxTokens) ?? $config->getDefaultMaxTokens();
        $maxSteps = $this->evaluate($this->maxSteps) ?? $config->getDefaultMaxSteps();
        $topP = $this->evaluate($this->topP) ?? $config->getDefaultTopP();

        return [
            'temperature' => $temperature !== null ? (float) $temperature : null,
            'max_tokens' => $maxTokens,
            'max_steps' => $maxSteps,
            'top_p' => $topP !== null ? (float) $topP : null,
        ];
    }

    /**
     * Push the resolved options onto the agent (no-op for any option resolved to null).
     */
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

- [ ] **Step 2: Verify PHPStan**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && composer phpstan
```

Expected: `[OK] No errors`. (The trait references `$this->evaluate()` which PHPStan won't resolve standalone — if it complains, add `@phpstan-ignore-line` or annotate `/** @phpstan-self-out method-call */` is overkill; the simplest fix is `@method mixed evaluate(mixed $value)` at the trait class level. Try without first; only add if PHPStan complains.)

- [ ] **Step 3: Verify tests still green**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && composer test
```

Expected: 497 pass (trait isn't wired in yet, so no behaviour change).

- [ ] **Step 4: Commit**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris
git add src/Concerns/HasGenerationOptions.php
git commit -m "feat: add HasGenerationOptions trait (shared option setters + apply helper)"
```

---

### Task 2: Hoist `resolveProviderAndModel()` and `resolveTimeout()` onto `SolarisAction`

**Files:**
- Modify: `src/Actions/SolarisAction.php` (add two protected methods after the existing `resolveActionProvider()` / `resolveActionTimeout()` block, around line ~210).

These are the literal bodies that `AiGenerateAction` carries today — they move up. `HasPromptPipeline` will override both for preset-aware fallback.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/AiGenerateActionTest.php`. We test through `AiGenerateAction` because it's the consumer that will inherit unchanged — and these tests will keep passing after Task 4 removes the local copies.

```php
it('falls back to config default_provider/default_model when no action override is set', function () {
    config([
        'filament-solaris.default_provider' => ['anthropic'],
        'filament-solaris.default_model' => 'claude-opus-4-7',
    ]);

    $action = AiGenerateAction::make('test')->outputSchema(fn ($schema) => ['x' => $schema->string()]);

    $ref = new ReflectionMethod($action, 'resolveProviderAndModel');
    $ref->setAccessible(true);
    $result = $ref->invoke($action);

    expect($result)->toBe(['provider' => ['anthropic'], 'model' => 'claude-opus-4-7']);
});

it('falls back to config default_timeout when no action override is set', function () {
    config(['filament-solaris.default_timeout' => 42]);

    $action = AiGenerateAction::make('test')->outputSchema(fn ($schema) => ['x' => $schema->string()]);

    $ref = new ReflectionMethod($action, 'resolveTimeout');
    $ref->setAccessible(true);

    expect($ref->invoke($action))->toBe(42);
});
```

Make sure `use ReflectionMethod;` is at the top of the file (add only if missing).

- [ ] **Step 2: Run tests, verify they pass**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && vendor/bin/pest tests/Feature/AiGenerateActionTest.php --filter="falls back to config"
```

Expected: 2 pass (`AiGenerateAction` already has these methods — these tests pin the contract before we move it).

- [ ] **Step 3: Read `SolarisAction.php` around lines 170–210**

```bash
sed -n '170,215p' src/Actions/SolarisAction.php
```

Find the closing `}` of `resolveActionTimeout()` (around line ~203). The new methods go directly after it.

- [ ] **Step 4: Add the two methods to `SolarisAction`**

Insert after the `resolveActionTimeout()` method:

```php
/**
 * Resolve provider+model: action-level override falls back to config defaults.
 * Subclasses with richer chains (e.g. preset-aware) override this.
 *
 * @return array{provider: \Cognesy\Polyglot\LLM\Lab|array<int|string, string>|string|null, model: ?string}
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

/**
 * Resolve timeout: action-level override falls back to config default.
 * Subclasses with richer chains (e.g. preset-aware) override this.
 */
protected function resolveTimeout(): ?int
{
    return $this->resolveActionTimeout() ?? FilamentSolaris::config()->getDefaultTimeout();
}
```

Verify imports — `FilamentSolaris` should already be imported (the action-level helpers reference it). If not, add `use Statikbe\FilamentSolaris\FilamentSolaris;`. The `Lab` type for the PHPDoc is the one already used in `AiGenerateAction.php`; confirm by `grep -n "Lab" src/Actions/AiGenerateAction.php` and copy the same `use` line if needed.

- [ ] **Step 5: Run all tests — must still pass**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && composer test
```

Expected: 499 pass (497 baseline + 2 new). `AiGenerateAction`'s own copies still win (declared in the subclass shadow the base), and `HasPromptPipeline` already overrides — so no regression possible yet.

- [ ] **Step 6: PHPStan**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && composer phpstan
```

Expected: `[OK] No errors`.

- [ ] **Step 7: Commit**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris
git add src/Actions/SolarisAction.php tests/Feature/AiGenerateActionTest.php
git commit -m "feat: hoist resolveProviderAndModel/resolveTimeout onto SolarisAction with config fallback"
```

---

### Task 3: Refactor `HasPromptPipeline` — adopt trait, rename internals, keep preset-aware overrides

**Files:**
- Modify: `src/Concerns/HasPromptPipeline.php`
- Test safety net: `tests/Feature/AiFormActionOptionsTest.php` (existing; must stay green throughout).

This task has **two semantic renames** that MUST go through PhpStorm MCP per `feedback_use_phpstorm_mcp_for_renames.md`:
1. `applyOptionsToAgent` → `applyGenerationOptions` (method).
2. `resolveOptions` → `resolveGenerationOptions` (method).

The four `pipeline*` properties and their setters are **deleted** (not renamed) because the trait introduces `$temperature`/`$maxTokens`/`$maxSteps`/`$topP` and identically-named public setters.

- [ ] **Step 1: Verify the safety-net tests pass before any change**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && vendor/bin/pest tests/Feature/AiFormActionOptionsTest.php
```

Expected: all pass (whatever count). This file's tests cover action setters + preset + config-default fallback. They are the contract that must not regress.

- [ ] **Step 2: Load the PhpStorm MCP rename schema**

```
ToolSearch query: select:mcp__phpstorm-index__ide_refactor_rename
```

Expected: returns the tool schema. Required because `phpstorm-index` tools are deferred.

- [ ] **Step 3: PhpStorm MCP rename — `applyOptionsToAgent` → `applyGenerationOptions`**

Call `mcp__phpstorm-index__ide_refactor_rename` with the method `applyOptionsToAgent` defined in `src/Concerns/HasPromptPipeline.php` (around line 547) → new name `applyGenerationOptions`. The IDE will update both call sites in the same file (line ~444 inside `runPipeline()` and line ~796 elsewhere — confirm via `grep -n applyOptionsToAgent src/Concerns/HasPromptPipeline.php` before, and `grep -n applyGenerationOptions src/Concerns/HasPromptPipeline.php` after to verify count matches).

If the IDE MCP is unavailable in the session, halt this task and surface that — do not fall back to sed.

- [ ] **Step 4: PhpStorm MCP rename — `resolveOptions` → `resolveGenerationOptions`**

Same procedure for the method `resolveOptions` (around line 280) → `resolveGenerationOptions`. Verify with `grep -n resolveGenerationOptions src/Concerns/HasPromptPipeline.php`.

- [ ] **Step 5: Run tests after renames — must stay green**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && composer test
```

Expected: 499 pass. (Pure renames have no behaviour change.)

- [ ] **Step 6: Commit the rename in isolation**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris
git add src/Concerns/HasPromptPipeline.php
git commit -m "refactor: rename HasPromptPipeline option helpers to match new trait names"
```

Why isolate the rename commit: it keeps the diff for the trait-adoption step focused on actual structural changes.

- [ ] **Step 7: Adopt the trait and delete duplicated properties + setters**

In `src/Concerns/HasPromptPipeline.php`:

a) Add `use HasGenerationOptions;` inside the trait body (after the existing `use` traits if any — `grep -n "^    use " src/Concerns/HasPromptPipeline.php` to find the spot; otherwise add as the first line inside the trait).

b) **Delete** the four property declarations:

```php
protected float|int|Closure|null $pipelineTemperature = null;
protected int|Closure|null $pipelineMaxTokens = null;
protected int|Closure|null $pipelineMaxSteps = null;
protected float|int|Closure|null $pipelineTopP = null;
```

c) **Delete** their four fluent setters. Find them with `grep -nE "public function (temperature|maxTokens|maxSteps|topP)\b" src/Concerns/HasPromptPipeline.php`. Delete each method body in full (including the doc comment if it's exclusive to the setter).

d) **Update** `resolveGenerationOptions()` to use the new trait-owned property names. The current body references `$this->pipelineTemperature` / `$this->pipelineMaxTokens` / `$this->pipelineMaxSteps` / `$this->pipelineTopP`. Rename each in-place to `$this->temperature` / `$this->maxTokens` / `$this->maxSteps` / `$this->topP`. Use a targeted Edit for each — these are property reads in a method body, not symbol renames, so sed/Edit is fine here.

The preset-aware override stays intact (action setter via trait property → preset getter → preset config → config default).

- [ ] **Step 8: Run tests — preset-aware behaviour must be preserved**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && composer test
```

Expected: 499 pass. The `AiFormActionOptionsTest` cases (action setter, preset setter, config fallback, all four options) must all stay green — that's the proof the override survived the trait adoption.

- [ ] **Step 9: PHPStan**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && composer phpstan
```

Expected: `[OK] No errors`. PHPStan will catch any missed `$this->pipelineX` reference.

- [ ] **Step 10: Pint**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && vendor/bin/pint src/Concerns/HasPromptPipeline.php
```

- [ ] **Step 11: Commit**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris
git add src/Concerns/HasPromptPipeline.php
git commit -m "refactor: HasPromptPipeline adopts HasGenerationOptions trait"
```

---

### Task 4: Refactor `AiGenerateAction` — adopt trait, drop duplicate resolves, apply options to agent

**Files:**
- Modify: `src/Actions/AiGenerateAction.php`
- Test: `tests/Feature/AiGenerateActionTest.php` (write new tests first).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/AiGenerateActionTest.php`:

```php
it('applies temperature, maxTokens, maxSteps, and topP to the agent', function () {
    $action = AiGenerateAction::make('test')
        ->outputSchema(fn ($schema) => ['x' => $schema->string()])
        ->temperature(0.9)
        ->maxTokens(2000)
        ->maxSteps(7)
        ->topP(0.85);

    $agent = new SolarisAgent;

    $ref = new ReflectionMethod($action, 'applyGenerationOptions');
    $ref->setAccessible(true);
    $ref->invoke($action, $agent);

    expect($agent->temperature())->toBe(0.9)
        ->and($agent->maxTokens())->toBe(2000)
        ->and($agent->maxSteps())->toBe(7)
        ->and($agent->topP())->toBe(0.85);
});

it('falls back to config defaults for AiGenerateAction generation options when not set', function () {
    config([
        'filament-solaris.default_temperature' => 0.5,
        'filament-solaris.default_max_tokens' => 1500,
        'filament-solaris.default_max_steps' => 4,
        'filament-solaris.default_top_p' => 0.9,
    ]);

    $action = AiGenerateAction::make('test')->outputSchema(fn ($schema) => ['x' => $schema->string()]);

    $ref = new ReflectionMethod($action, 'resolveGenerationOptions');
    $ref->setAccessible(true);

    expect($ref->invoke($action))->toBe([
        'temperature' => 0.5,
        'max_tokens' => 1500,
        'max_steps' => 4,
        'top_p' => 0.9,
    ]);
});
```

Ensure `use Statikbe\FilamentSolaris\Support\SolarisAgent;` is present at the top of the test file (add if missing). `ReflectionMethod` was already added in Task 2.

- [ ] **Step 2: Run tests — confirm both new tests fail**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && vendor/bin/pest tests/Feature/AiGenerateActionTest.php --filter="applies temperature|falls back to config defaults for AiGenerateAction"
```

Expected: both fail with `Error: Call to undefined method ... applyGenerationOptions()` / `... resolveGenerationOptions()` (those methods live on the trait we haven't adopted yet on this class).

- [ ] **Step 3: Adopt the trait on `AiGenerateAction`**

Add `use HasGenerationOptions;` inside the class body (near the other `use TraitName;` lines — `grep -n "^    use " src/Actions/AiGenerateAction.php` to find the spot). Also add the import at the top:

```php
use Statikbe\FilamentSolaris\Concerns\HasGenerationOptions;
```

(Keep the imports alphabetised if the file follows that order — peek at the existing block.)

- [ ] **Step 4: Delete the duplicate resolves**

Find with `grep -nE "function (resolveProviderAndModel|resolveTimeout)" src/Actions/AiGenerateAction.php` — they sit around lines 354–369. Delete both methods (and their docblocks). They are now inherited from `SolarisAction` (Task 2).

- [ ] **Step 5: Apply options to the agent in `execute()`**

In `execute()` (around line 213), after the line:
```php
$agent = (new SolarisAgent)->configure($instruction, [], $resolver);
```

Insert:
```php
$this->applyGenerationOptions($agent);
```

- [ ] **Step 6: Apply options to the agent in `generateForRow()`**

In `generateForRow()` (around line 502), after the line:
```php
$agent = (new SolarisAgent)->configure($instruction, [], $resolver);
```

Insert:
```php
$this->applyGenerationOptions($agent);
```

- [ ] **Step 7: Run the new tests — must pass**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && vendor/bin/pest tests/Feature/AiGenerateActionTest.php --filter="applies temperature|falls back to config defaults for AiGenerateAction"
```

Expected: both pass.

- [ ] **Step 8: Run the full suite — no regression**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && composer test
```

Expected: 501 pass (499 from Task 2 + 2 new here). The Task 2 tests (the provider/timeout reflection ones) still pass because the action now inherits the same behaviour from `SolarisAction`.

- [ ] **Step 9: PHPStan + Pint**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && composer phpstan && vendor/bin/pint src/Actions/AiGenerateAction.php tests/Feature/AiGenerateActionTest.php
```

Expected: `[OK] No errors` from PHPStan; Pint reports formatting touches or "Nothing to fix".

- [ ] **Step 10: Commit**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris
git add src/Actions/AiGenerateAction.php tests/Feature/AiGenerateActionTest.php
git commit -m "feat: AiGenerateAction supports ->temperature/maxTokens/maxSteps/topP via HasGenerationOptions"
```

---

### Task 5: Documentation

**Files:**
- Modify: `documentation/ai-generate-action.md`
- Modify: `CHANGELOG.md`
- Modify: `specs/missing-features.md`

- [ ] **Step 1: Read the AiFormAction "Generation Options" section verbatim — we mirror it**

```bash
sed -n '205,235p' documentation/ai-form-action.md
```

We copy that prose with `AiFormAction` → `AiGenerateAction` substitutions and drop the preset-related lines (presets don't apply to `AiGenerateAction`).

- [ ] **Step 2: Add the "Generation options" section to `documentation/ai-generate-action.md`**

Find a natural anchor — somewhere after the schema/forModel sections and before the "v1 limitations" callout. `grep -n "^## " documentation/ai-generate-action.md` to see existing headers; insert before the closing limitations callout (line ~294).

Insert:

```markdown
## Generation Options

Tune the underlying text generation. All four options are optional — when not set, the resolver falls back to the config `default_*` keys, and ultimately to `laravel/ai`'s own attribute defaults.

```php
AiGenerateAction::make('seed-articles')
    ->forModel(Article::class)
    ->count(20)
    ->temperature(0.9)   // creativity for diverse seed data
    ->maxTokens(2000)
    ->maxSteps(5)
    ->topP(0.95)
    ->createRecords();
```

Setters accept `Closure` for runtime values:

```php
AiGenerateAction::make('seed-articles')
    ->temperature(fn () => auth()->user()->ai_creativity ?? 0.7)
```

Resolution chain per option (highest wins): action → config `default_*` → `laravel/ai` default. See [Configuration](configuration.md) for the package-wide `default_temperature` / `default_max_tokens` / `default_max_steps` / `default_top_p` keys.
```

- [ ] **Step 3: Trim the v1-limitations callout**

Read the existing callout:

```bash
sed -n '290,300p' documentation/ai-generate-action.md
```

The current line (294) reads:
> **Generation options** (`->temperature()`, `->maxTokens()`, `->maxSteps()`, `->topP()`) are not available on `AiGenerateAction` in v1. **Preview and conversational refinement** are also not supported — the action executes, calls your handler, and returns. These features may be added in a later version.

Replace with:
> **Preview and conversational refinement** are not supported on `AiGenerateAction` — the action executes, calls your handler, and returns. These may be added in a later version.

(Removes the generation-options sentence; keeps the preview/conversational caveat.)

- [ ] **Step 4: CHANGELOG entry**

Open `CHANGELOG.md`, find `## [Unreleased]` → `### Added` (create the section if it doesn't exist). Add:

```markdown
- `AiGenerateAction` now supports `->temperature()`, `->maxTokens()`, `->maxSteps()`, and `->topP()` via the new shared `HasGenerationOptions` trait. Internal refactor: `HasPromptPipeline` and `AiGenerateAction` share generation-options + provider/timeout resolution machinery via the trait + a `SolarisAction` base; `HasPromptPipeline` keeps its preset-aware overrides.
```

- [ ] **Step 5: Trim `specs/missing-features.md`**

```bash
grep -n "AiGenerateAction\|generation options" specs/missing-features.md
```

Around line 218 there's the "Generation options on AiGenerateAction" follow-up bullet (or a fragment thereof inside the section). Remove that specific sentence/bullet; keep the rest of the section (the AiGenerateAction shipped status line stays).

- [ ] **Step 6: Commit**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris
git add documentation/ai-generate-action.md CHANGELOG.md specs/missing-features.md
git commit -m "docs: AiGenerateAction generation options + changelog entry"
```

---

### Task 6: Final verification + simplifier pass

**Files:** none modified directly by this task; simplifier may modify any of the above.

- [ ] **Step 1: Full test suite**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && composer test
```

Expected: 501 pass.

- [ ] **Step 2: PHPStan with the recommended memory limit**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && php -d memory_limit=512M vendor/bin/phpstan analyse
```

Expected: `[OK] No errors`.

- [ ] **Step 3: Pint sweep**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && vendor/bin/pint
```

Expected: clean (any touch-ups commit separately).

- [ ] **Step 4: Run laravel-simplifier on the changed surface**

Dispatch the `laravel-simplifier` agent (per `feedback_run_simplifier.md`) scoped to the diff vs `main`:
- `src/Concerns/HasGenerationOptions.php`
- `src/Concerns/HasPromptPipeline.php`
- `src/Actions/SolarisAction.php`
- `src/Actions/AiGenerateAction.php`

Review the agent's suggestions. Accept only changes that simplify without changing behaviour; defer architectural pushback to the user.

- [ ] **Step 5: If simplifier made changes, re-run tests + PHPStan + Pint, then commit**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && composer test && composer phpstan && vendor/bin/pint
git add -A && git commit -m "chore: simplifier pass on shared AI-call plumbing"
```

- [ ] **Step 6: Review the full branch diff before handoff**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && git log --oneline main..HEAD && git diff main...HEAD --stat
```

Sanity-check: ~6 commits (spec + 5 implementation + maybe simplifier); the diff stats should show:
- `HasPromptPipeline.php` net -lines (removed 4 setters + 4 props).
- `AiGenerateAction.php` net roughly neutral (added trait use + 2 apply calls, removed 2 resolve methods).
- New trait file ~75 lines.
- `SolarisAction.php` ~+20 lines (2 small methods).

- [ ] **Step 7: Hand off to `superpowers:finishing-a-development-branch`**

Invoke the skill to present merge/PR/keep/discard options.

---

## Self-Review

**Spec coverage check (`specs/25-shared-ai-call-plumbing.md`):**
- "New: `src/Concerns/HasGenerationOptions.php`" → Task 1 ✅
- "Modified: `src/Actions/SolarisAction.php`" (two new resolves with config fallback) → Task 2 ✅
- "Modified: `src/Concerns/HasPromptPipeline.php`" (adopt trait, delete pipeline* props/setters, rename internals, override preset-aware resolves) → Task 3 ✅
- "Modified: `src/Actions/AiGenerateAction.php`" (adopt trait, drop own resolves, apply in execute + generateForRow) → Task 4 ✅
- "Testing" (two new AiGenerateAction tests + AiFormActionOptionsTest as safety net) → Task 4 covers the two new tests; Task 3 explicitly re-runs AiFormActionOptionsTest after the override change ✅
- "Documentation" (ai-generate-action.md section + v1-limitations trim, CHANGELOG, missing-features trim) → Task 5 ✅

**Naming consistency:**
- Trait: `HasGenerationOptions` — consistent across Tasks 1, 3, 4.
- Method: `applyGenerationOptions(SolarisAgent $agent): void` — consistent.
- Method: `resolveGenerationOptions(): array` — consistent.
- Properties: `$temperature`, `$maxTokens`, `$maxSteps`, `$topP` (no `pipeline` prefix) — consistent.

**Placeholder scan:** No "TBD", "implement later", or "appropriate error handling" wording. Every code block is the actual code to write.

**PhpStorm MCP discipline:** Task 3 explicitly routes both true renames (`applyOptionsToAgent`, `resolveOptions`) through `mcp__phpstorm-index__ide_refactor_rename` per `feedback_use_phpstorm_mcp_for_renames.md`. Property reads (`$this->pipelineTemperature` → `$this->temperature`) are intra-method reads, not symbol renames — Edit/sed is fine for those and the plan says so.

**Trait method resolution sanity check:** When `HasPromptPipeline` uses `HasGenerationOptions` AND declares its own `resolveGenerationOptions()` method, PHP's resolution gives the using class's method precedence over the trait's method (no `Conflict` error needed because the outer trait declares it directly, not via another `use`). `AiFormAction` uses `HasPromptPipeline`, so it gets the preset-aware override. `AiGenerateAction` uses `HasGenerationOptions` directly (no override), so it gets the base resolve. Validated by Task 3 keeping `AiFormActionOptionsTest` green.
