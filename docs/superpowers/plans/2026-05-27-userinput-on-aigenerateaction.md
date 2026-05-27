# UserInput on `AiGenerateAction` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `UserInput`-modal support to `AiGenerateAction`. Modal values auto-inject into the prompt as a `## User context` block (top-level + per-row) and are available as a `$userInput` closure named-arg in `->prompt()`, `->handleUsing()`, and `->sourceRecords()`. Trait split (`HasUserInput` slim + new `HasDefaultUserInput`) keeps the door open for presets on `AiGenerateAction` later without forcing them now.

**Architecture:** Today's `HasUserInput` carries `withDefaultUserInput()`, which reads `$this->promptBuilder` — only meaningful on `HasPromptPipeline` consumers. Split the trait: `HasUserInput` (setter + getters + schema) stays generic; `HasDefaultUserInput` (the preset-default logic) becomes opt-in. `HasPromptPipeline` adopts both → `AiFormAction` behaviour unchanged. `AiGenerateAction` adopts only `HasUserInput`, threads `$userInput` through every execute branch (single-call, fake, records loop, per-row), and appends a `## User context` JSON block to the instruction parallel to today's `## Current record`.

**Tech Stack:** PHP 8.3, Filament v4, laravel/ai v0.6.4+, Pest, PHPStan L5, Pint. Spec: `specs/24-userinput-on-aigenerateaction.md`.

---

## File Structure

**New:**
- `src/Concerns/HasDefaultUserInput.php` (~30 lines) — owns `withDefaultUserInput()` + the preset-default branch. Used by `HasPromptPipeline`; **not** used by `AiGenerateAction`.
- `tests/Feature/AiGenerateActionUserInputTest.php` — 10 Pest tests (see Task 7).

**Modified:**
- `src/Concerns/HasUserInput.php` — strip `withDefaultUserInput()` + `$useDefaultUserInput`; `getUserInput()` delegates to `$this->getDefaultUserInputFromBuilder()` via `method_exists()` guard.
- `src/Concerns/HasPromptPipeline.php` — add `use HasDefaultUserInput;` next to existing `use HasUserInput;`. Zero behaviour change.
- `src/Actions/AiGenerateAction.php` — adopt `HasUserInput`; delete hardcoded `hasUserInput()` override (line ~415); change `setUp()` action callback + schema callback; thread `$userInput` through 8 methods; auto-inject `## User context` block in two places.
- `src/Testing/AiGenerateActionFake.php` — `recordCall($name, $data, $userInput = [])`; new `assertCalledWithUserInput(Closure)` helper.
- `tests/Fixtures/GenerateFormComponent.php` — 5 new action methods for the new tests (see Task 7).
- `documentation/ai-generate-action.md` — new "User Input" section between "Provider, Model & Timeout" (ends ~line 291) and "Generation Options" (starts line 293).
- `CHANGELOG.md` — entry under `## [Unreleased] > ### Added`.

**Branch state at start:** `main` is clean (post merge of `feature/shared-ai-call-plumbing`). Create branch `feature/userinput-on-aigenerateaction` for this work.

---

### Task 0: Setup + sanity check

**Files:** none.

- [ ] **Step 1: Create the working branch**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris
git checkout main
git status --porcelain
git checkout -b feature/userinput-on-aigenerateaction
```

Expected: `git status --porcelain` is empty (clean tree) before the `checkout -b`. Branch switches to `feature/userinput-on-aigenerateaction`.

- [ ] **Step 2: Baseline green**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && composer test
```

Expected: 501 tests pass.

- [ ] **Step 3: Baseline PHPStan**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && php -d memory_limit=512M vendor/bin/phpstan analyse
```

Expected: `[OK] No errors`.

---

### Task 1: Split `HasUserInput` — extract `HasDefaultUserInput`

**Files:**
- Create: `src/Concerns/HasDefaultUserInput.php`
- Modify: `src/Concerns/HasUserInput.php`

The split must be **behaviour-preserving** for `AiFormAction`. Strategy: extract the preset-default bits into a sibling trait, then have `HasUserInput::getUserInput()` delegate to a hook method (`getDefaultUserInputFromBuilder()`) that only exists when the sibling trait is also adopted. `method_exists($this, '...')` guard keeps `HasUserInput` standalone-usable.

- [ ] **Step 1: Create `src/Concerns/HasDefaultUserInput.php`**

```php
<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Statikbe\FilamentSolaris\Support\UserInput;

/**
 * Adds preset-default-user-input behaviour to a consumer that already uses
 * {@see HasUserInput} AND owns a `$promptBuilder` property (i.e.
 * {@see HasPromptPipeline}).
 *
 * Standalone-useless: the sibling `HasUserInput::getUserInput()` calls
 * `getDefaultUserInputFromBuilder()` only when this trait is also adopted
 * (detected via `method_exists()`).
 */
trait HasDefaultUserInput
{
    protected bool $useDefaultUserInput = false;

    /**
     * Opt in to the prompt builder's default user input.
     *
     * Resolution is deferred to {@see HasUserInput::getUserInput()} so call
     * order doesn't matter — `->withDefaultUserInput()` works whether it
     * runs before or after `->prompt()` / `->preset()` (which set
     * `$this->promptBuilder`). An explicit `->userInput()` always takes
     * precedence over the default.
     */
    public function withDefaultUserInput(): static
    {
        $this->useDefaultUserInput = true;

        return $this;
    }

    /**
     * Hook called by {@see HasUserInput::getUserInput()} when no explicit
     * `->userInput()` was set. Reads the preset's defaultUserInput()
     * iff the flag is on and a prompt builder is present.
     */
    protected function getDefaultUserInputFromBuilder(): ?UserInput
    {
        if ($this->useDefaultUserInput && isset($this->promptBuilder)) {
            return $this->promptBuilder->defaultUserInput();
        }

        return null;
    }
}
```

- [ ] **Step 2: Slim down `src/Concerns/HasUserInput.php`**

Replace the entire file with:

```php
<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Closure;
use Filament\Schemas\Components\Component;
use Statikbe\FilamentSolaris\Support\UserInput;

trait HasUserInput
{
    protected UserInput|Closure|null $userInput = null;

    /**
     * Set the user input configuration.
     */
    public function userInput(UserInput|Closure $userInput): static
    {
        $this->userInput = $userInput;

        return $this;
    }

    /**
     * Check if user input is configured.
     */
    public function hasUserInput(): bool
    {
        return $this->getUserInput() !== null;
    }

    public function getUserInput(): ?UserInput
    {
        if ($this->userInput !== null) {
            return value($this->userInput);
        }

        if (method_exists($this, 'getDefaultUserInputFromBuilder')) {
            return $this->getDefaultUserInputFromBuilder();
        }

        return null;
    }

    /**
     * Get the Filament form schema for the user input modal.
     *
     * @return array<Component>
     */
    public function getUserInputFormSchema(): array
    {
        $userInput = $this->getUserInput();

        if ($userInput === null) {
            return [];
        }

        return $userInput->toFormSchema();
    }
}
```

- [ ] **Step 3: Add `use HasDefaultUserInput;` to `HasPromptPipeline`**

```bash
grep -n "use HasUserInput" src/Concerns/HasPromptPipeline.php
```

Expected: exactly one match (the trait adoption line). Edit that file to insert the new use immediately above it:

```php
// Before:
    use HasUserInput;

// After:
    use HasDefaultUserInput;
    use HasUserInput;
```

(Alphabetical order — `HasDefaultUserInput` < `HasUserInput`.)

- [ ] **Step 4: Run the full suite — no regression**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && composer test
```

Expected: 501 tests pass. **Critical regression check:** anything exercising `AiFormAction::withDefaultUserInput()` (look for tests in `tests/Feature/AiFormActionUserInputTest.php` or similar) must stay green — that's the proof the split preserved behaviour.

- [ ] **Step 5: PHPStan**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && php -d memory_limit=512M vendor/bin/phpstan analyse
```

Expected: `[OK] No errors`.

- [ ] **Step 6: Pint**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && vendor/bin/pint src/Concerns/HasUserInput.php src/Concerns/HasDefaultUserInput.php src/Concerns/HasPromptPipeline.php
```

Expected: clean or "fixed" — no failures.

- [ ] **Step 7: Commit**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris
git add src/Concerns/HasUserInput.php src/Concerns/HasDefaultUserInput.php src/Concerns/HasPromptPipeline.php
git commit -m "refactor: split HasUserInput → extract HasDefaultUserInput for preset-default logic"
```

---

### Task 2: `AiGenerateAction` adopts `HasUserInput` (no behaviour change yet)

**Files:**
- Modify: `src/Actions/AiGenerateAction.php`

This task is a structural prep: bring the trait in, delete the hardcoded `hasUserInput()` override, but **don't change** `execute()` or the modal wiring yet. After this task `->userInput()` is callable but it has no visible effect (modal still doesn't open because no `schema()` callback is registered, and `execute()` still ignores any data).

- [ ] **Step 1: Add the import**

`grep -n "^use Statikbe" src/Actions/AiGenerateAction.php | head -5` — confirm the existing import block layout.

Insert `use Statikbe\FilamentSolaris\Concerns\HasUserInput;` in alphabetical order with the existing `Statikbe\FilamentSolaris\Concerns\HasGenerationOptions` import.

- [ ] **Step 2: Add `use HasUserInput;` inside the class**

Find the existing trait adoption block (`grep -n "^    use " src/Actions/AiGenerateAction.php` — expect `use HasGenerationOptions;` at line ~34). Add `use HasUserInput;` directly after it (alphabetical: `HasGenerationOptions` < `HasUserInput`):

```php
    use HasGenerationOptions;
    use HasUserInput;
```

- [ ] **Step 3: Delete the hardcoded `hasUserInput()` override**

Find it: `grep -nE "function hasUserInput" src/Actions/AiGenerateAction.php` → currently around line 415.

Delete the entire method (and its docblock if it has a dedicated one — peek at the 2 lines above the `public function`):

```php
    public function hasUserInput(): bool
    {
        return false;
    }
```

The trait's default takes over (returns `$this->getUserInput() !== null` — false when no `->userInput()` was set, matching today's behaviour).

- [ ] **Step 4: Run the full suite — no behaviour change expected**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && composer test
```

Expected: 501 tests pass. No tests assert `hasUserInput()` is hardcoded false — the trait's `$userInput === null` produces the same observable result.

- [ ] **Step 5: PHPStan + Pint**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && php -d memory_limit=512M vendor/bin/phpstan analyse && vendor/bin/pint src/Actions/AiGenerateAction.php
```

Expected: PHPStan clean, Pint clean.

- [ ] **Step 6: Commit**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris
git add src/Actions/AiGenerateAction.php
git commit -m "refactor: AiGenerateAction adopts HasUserInput trait (structural prep)"
```

---

### Task 3: Modal wiring + `execute(array $data = [])` signature

**Files:**
- Modify: `src/Actions/AiGenerateAction.php`

This task wires the modal but doesn't yet thread `$userInput` into closures — execute paths get `$userInput` as a parameter, defaulting to `[]`, but the parameter is unused until Task 4. Each step below is small; do not skip.

- [ ] **Step 1: Add `schema()` callback in `setUp()` + update `action()` signature**

Current `setUp()` (around lines 75–84):

```php
    protected function setUp(): void
    {
        parent::setUp();

        $this->icon(FilamentSolaris::config()->getActionIcon());

        $this->action(function (AiGenerateAction $action): void {
            $action->execute();
        });
    }
```

Replace with:

```php
    protected function setUp(): void
    {
        parent::setUp();

        $this->icon(FilamentSolaris::config()->getActionIcon());

        $this->schema(fn (AiGenerateAction $action): array => $action->getUserInputFormSchema());

        $this->action(function (AiGenerateAction $action, array $data = []): void {
            $action->execute($data);
        });
    }
```

- [ ] **Step 2: Update `execute()` signature**

`grep -n "public function execute" src/Actions/AiGenerateAction.php` — currently at line 215.

Change:

```php
    public function execute(): void
```

to:

```php
    public function execute(array $data = []): void
```

Add `$userInput = $data;` as the first line of the method body (right after the opening `{`). This local alias keeps the rest of the method readable as we thread `$userInput`.

Then thread `$userInput` through the existing internal calls — for now just **pass the empty default**; closures and auto-inject are Task 4. Locate inside the method:

- `$this->executeRecordsLoop();` → `$this->executeRecordsLoop($userInput);`
- `$this->executeFake();` → `$this->executeFake($userInput);`
- `$instruction = $this->resolveInstruction();` → `$instruction = $this->resolveInstruction($userInput);`
- `$this->dispatchSingleResponse($response->toArray());` → `$this->dispatchSingleResponse($response->toArray(), $userInput);`

- [ ] **Step 3: Add the `array $userInput = []` parameter to each receiving method**

For each method below, add `array $userInput = []` to its signature. **Do not yet use `$userInput` inside the bodies** — that's Task 4.

| Method | Current signature | New signature |
|---|---|---|
| `executeFake` (line ~254) | `protected function executeFake(): void` | `protected function executeFake(array $userInput = []): void` |
| `dispatchSingleResponse` (line ~280) | `protected function dispatchSingleResponse(array $data): void` | `protected function dispatchSingleResponse(array $data, array $userInput = []): void` |
| `resolveInstruction` (line ~306) | `protected function resolveInstruction(): string` | `protected function resolveInstruction(array $userInput = []): string` |
| `executeRecordsLoop` (line ~422) | `protected function executeRecordsLoop(): void` | `protected function executeRecordsLoop(array $userInput = []): void` |
| `resolveRecordsSource` (line ~460) | `protected function resolveRecordsSource(): iterable` | `protected function resolveRecordsSource(array $userInput = []): iterable` |
| `generateForRow` (line ~484) | `protected function generateForRow(array\|Model $row, Closure $resolver, mixed $provider, ?string $model, ?int $timeout): ?array` | `protected function generateForRow(array\|Model $row, Closure $resolver, mixed $provider, ?string $model, ?int $timeout, array $userInput = []): ?array` |
| `resolveInstructionForRow` (line ~545) | `protected function resolveInstructionForRow(array\|Model $row): string` | `protected function resolveInstructionForRow(array\|Model $row, array $userInput = []): string` |

- [ ] **Step 4: Thread `$userInput` through the inner call sites**

Inside `executeRecordsLoop` (look at lines ~424–435):

- `$rows = $this->resolveRecordsSource();` → `$rows = $this->resolveRecordsSource($userInput);`
- `$attrs = $this->generateForRow($row, $resolver, $provider, $model, $timeout);` → `$attrs = $this->generateForRow($row, $resolver, $provider, $model, $timeout, $userInput);`

Inside `generateForRow` (look at lines ~490–510 — there are two `resolveInstructionForRow($row)` calls in the fake branch and the real branch):

- Both `$this->resolveInstructionForRow($row)` → `$this->resolveInstructionForRow($row, $userInput)`

That's the threading. **Nothing uses `$userInput` yet** — that's intentional. Task 4 adds the actual usage.

- [ ] **Step 5: Run the full suite — must stay green**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && composer test
```

Expected: 501 tests pass. The signature changes are all backward-compatible (default `[]`), and no logic uses `$userInput` yet.

- [ ] **Step 6: PHPStan**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && php -d memory_limit=512M vendor/bin/phpstan analyse
```

Expected: `[OK] No errors`.

- [ ] **Step 7: Pint**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && vendor/bin/pint src/Actions/AiGenerateAction.php
```

- [ ] **Step 8: Commit**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris
git add src/Actions/AiGenerateAction.php
git commit -m "feat: thread \$userInput through AiGenerateAction execute paths (modal-ready, unused)"
```

---

### Task 4: Closure DI + auto-inject `## User context` block

**Files:**
- Modify: `src/Actions/AiGenerateAction.php`

Now actually use `$userInput`. Two things wire up in this task:
1. **Closure DI named-arg** — every `$this->evaluate($closure, [...])` call site adds `'userInput' => $userInput`.
2. **Auto-inject `## User context` block** — appended to the instruction in both `resolveInstruction()` and `resolveInstructionForRow()` when `$userInput` has any `filled()` values.

- [ ] **Step 1: Closure DI in `resolveInstruction()`**

Locate (line ~306):

```php
    protected function resolveInstruction(array $userInput = []): string
    {
        $instruction = $this->instruction;

        if ($instruction instanceof Closure) {
            $instruction = $this->evaluate($instruction);
        }
```

Change the `evaluate` call to pass `$userInput` as a named arg:

```php
        if ($instruction instanceof Closure) {
            $instruction = $this->evaluate($instruction, ['userInput' => $userInput]);
        }
```

- [ ] **Step 2: Auto-inject `## User context` block in `resolveInstruction()`**

After the `View` rendering branch, before the final `return $instruction;`, append the block. The full method after this step should look like:

```php
    protected function resolveInstruction(array $userInput = []): string
    {
        $instruction = $this->instruction;

        if ($instruction instanceof Closure) {
            $instruction = $this->evaluate($instruction, ['userInput' => $userInput]);
        }

        if ($instruction instanceof View) {
            $instruction = $instruction->render();
        }

        $instruction = (string) $instruction;

        return $this->appendUserContext($instruction, $userInput);
    }
```

(The actual existing method may have slightly different formatting between the View branch and the return — preserve it; the only addition is the `appendUserContext()` call replacing the existing `return $instruction;`.)

- [ ] **Step 3: Add the `appendUserContext()` helper**

Place the helper near the other instruction helpers (e.g. just after `resolveInstructionForRow()` — or wherever the file groups protected helpers; pick a logical spot and stay consistent):

```php
    /**
     * Append a `## User context` JSON block to the instruction when the
     * user-input modal yielded any filled values. No-op for empty input.
     *
     * @param  array<string, mixed>  $userInput
     */
    protected function appendUserContext(string $instruction, array $userInput): string
    {
        $filtered = array_filter($userInput, static fn ($v): bool => filled($v));

        if ($filtered === []) {
            return $instruction;
        }

        $json = json_encode($filtered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return trim($instruction)."\n\n## User context\n```json\n{$json}\n```";
    }
```

- [ ] **Step 4: Closure DI + auto-inject in `resolveInstructionForRow()`**

Locate (line ~545):

```php
    protected function resolveInstructionForRow(array|Model $row, array $userInput = []): string
    {
        $instruction = $this->instruction;

        if ($instruction instanceof Closure) {
            $instruction = $this->evaluate($instruction, [
                'row' => $row instanceof Model ? $row->getAttributes() : $row,
            ]);
        }
```

Update the `evaluate` args to include `userInput`:

```php
        if ($instruction instanceof Closure) {
            $instruction = $this->evaluate($instruction, [
                'row' => $row instanceof Model ? $row->getAttributes() : $row,
                'userInput' => $userInput,
            ]);
        }
```

Then locate the existing `## Current record` block (the lines ending around line 565):

```php
        $context = $this->buildContextForRow($row);

        if ($context !== []) {
            $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $instruction = trim($instruction)."\n\n## Current record\n```json\n{$json}\n```";
        }

        return $instruction;
```

Change to inject `## User context` **before** `## Current record`:

```php
        $instruction = $this->appendUserContext($instruction, $userInput);

        $context = $this->buildContextForRow($row);

        if ($context !== []) {
            $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $instruction = trim($instruction)."\n\n## Current record\n```json\n{$json}\n```";
        }

        return $instruction;
```

- [ ] **Step 5: Closure DI in `dispatchSingleResponse()`**

Locate (line ~280):

```php
            $this->evaluate($this->handler, [
                'data' => $data,
                'records' => $this->modelClass !== null ? ($data[self::RECORDS_KEY] ?? []) : null,
            ]);
```

Add `'userInput' => $userInput`:

```php
            $this->evaluate($this->handler, [
                'data' => $data,
                'records' => $this->modelClass !== null ? ($data[self::RECORDS_KEY] ?? []) : null,
                'userInput' => $userInput,
            ]);
```

- [ ] **Step 6: Closure DI in `resolveRecordsSource()`**

Locate (line ~460):

```php
    protected function resolveRecordsSource(array $userInput = []): iterable
    {
        $source = $this->source instanceof Closure ? $this->evaluate($this->source) : $this->source;
```

Change the `evaluate` to pass `userInput`:

```php
        $source = $this->source instanceof Closure
            ? $this->evaluate($this->source, ['userInput' => $userInput])
            : $this->source;
```

- [ ] **Step 7: Run the full suite — must stay green**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && composer test
```

Expected: 501 tests pass. All existing closures ignore the new `$userInput` named arg (Filament's `evaluate()` only injects parameters the closure declares — backward-compatible).

- [ ] **Step 8: PHPStan + Pint**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && php -d memory_limit=512M vendor/bin/phpstan analyse && vendor/bin/pint src/Actions/AiGenerateAction.php
```

Expected: PHPStan clean, Pint clean.

- [ ] **Step 9: Commit**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris
git add src/Actions/AiGenerateAction.php
git commit -m "feat: \$userInput closure DI + auto-inject ## User context block on AiGenerateAction"
```

---

### Task 5: Fake plumbing — `recordCall` captures `$userInput`

**Files:**
- Modify: `src/Testing/AiGenerateActionFake.php`
- Modify: `src/Actions/AiGenerateAction.php` (pass `$userInput` to the two `recordCall` call sites)

- [ ] **Step 1: Update `recordCall` signature + storage**

Locate (line ~94):

```php
    /**
     * @param  array<string, mixed>  $data
     */
    public function recordCall(string $actionName, array $data): void
    {
        $this->calls[] = ['name' => $actionName, 'data' => $data];
    }
```

Change to:

```php
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $userInput
     */
    public function recordCall(string $actionName, array $data, array $userInput = []): void
    {
        $this->calls[] = ['name' => $actionName, 'data' => $data, 'userInput' => $userInput];
    }
```

- [ ] **Step 2: Add the `assertCalledWithUserInput()` helper**

Add after `assertCalledTimes` (around line 122):

```php
    /**
     * Assert that at least one recorded call's $userInput satisfies the callback.
     *
     * The callback receives the recorded array<string, mixed> $userInput
     * for each call; return true to indicate a match. Fails the test if
     * no call matches (or if no calls were recorded).
     *
     * @param  Closure(array<string, mixed>): bool  $callback
     */
    public function assertCalledWithUserInput(Closure $callback): void
    {
        Assert::assertNotEmpty($this->calls, 'Expected an AiGenerateAction call with userInput, but none was recorded.');

        foreach ($this->calls as $call) {
            if ($callback($call['userInput'] ?? []) === true) {
                return;
            }
        }

        Assert::fail('No AiGenerateAction call matched the userInput callback.');
    }
```

Add the `use Closure;` import at the top of the file if it's not already present (`grep -n "^use " src/Testing/AiGenerateActionFake.php`).

- [ ] **Step 3: Pass `$userInput` to `recordCall` in `executeFake`**

Locate in `src/Actions/AiGenerateAction.php` (~line 258):

```php
        $fake->recordCall($this->getName(), $data);
```

Change to:

```php
        $fake->recordCall($this->getName(), $data, $userInput);
```

- [ ] **Step 4: Pass `$userInput` to `recordCall` in `generateForRow` (per-row fake path)**

Locate inside `generateForRow` (~line 494, in the `if (AiGenerateActionFake::isActive())` branch):

```php
            $fake->recordCall($this->getName(), $data);
```

Change to:

```php
            $fake->recordCall($this->getName(), $data, $userInput);
```

- [ ] **Step 5: Run the full suite — must stay green**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && composer test
```

Expected: 501 tests pass. Existing `recordCall($name, $data)` calls keep working via the default `[]` third arg.

- [ ] **Step 6: PHPStan + Pint**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && php -d memory_limit=512M vendor/bin/phpstan analyse && vendor/bin/pint src/Testing/AiGenerateActionFake.php src/Actions/AiGenerateAction.php
```

Expected: PHPStan clean, Pint clean.

- [ ] **Step 7: Commit**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris
git add src/Testing/AiGenerateActionFake.php src/Actions/AiGenerateAction.php
git commit -m "feat: AiGenerateActionFake records \$userInput + assertCalledWithUserInput helper"
```

---

### Task 6: Add fixtures for UserInput tests

**Files:**
- Modify: `tests/Fixtures/GenerateFormComponent.php`

Five new action methods host the configured actions for Task 7's tests. Pattern mirrors the existing `seedCategoriesAction()` / `enrichCategoriesAction()` shape (see `tests/Fixtures/GenerateFormComponent.php` lines ~91–120 for the template).

- [ ] **Step 1: Read the existing fixture imports**

```bash
grep -n "^use " tests/Fixtures/GenerateFormComponent.php
```

Confirm `Textarea` and `UserInput` are imported. If not, add:

```php
use Filament\Forms\Components\Textarea;
use Statikbe\FilamentSolaris\Support\UserInput;
```

- [ ] **Step 2: Add `userInputSingleCallAction()`**

Append at the end of the class body (before the closing `}`):

```php
    public function userInputSingleCallAction(): AiGenerateAction
    {
        return AiGenerateAction::make('userInputSingleCall')
            ->userInput(UserInput::make()->fields([Textarea::make('focus')]))
            ->prompt(fn (array $userInput) => 'Generate for focus: '.($userInput['focus'] ?? 'none'))
            ->outputSchema(fn ($schema) => ['x' => $schema->string()])
            ->handleUsing(function (array $data, array $userInput) {
                $this->handledData = ['data' => $data, 'userInput' => $userInput];
            });
    }
```

(Confirm `$handledData` exists as a public property on the component — check around line 15 of the fixture. If it doesn't, add `public array $handledData = [];` near the other public properties.)

- [ ] **Step 3: Add `userInputCreateRecordsLoopAction()`**

```php
    public function userInputCreateRecordsLoopAction(): AiGenerateAction
    {
        return AiGenerateAction::make('userInputCreateRecordsLoop')
            ->userInput(UserInput::make()->fields([Textarea::make('focus')]))
            ->forModel(SeedCategory::class)
            ->prompt(fn (array $row, array $userInput) => "Process row {$row['name']} with focus: ".($userInput['focus'] ?? 'none'))
            ->sourceRecords(fn (array $userInput) => [['name' => 'A', 'slug' => 'a'], ['name' => 'B', 'slug' => 'b']])
            ->createRecords();
    }
```

- [ ] **Step 4: Add `userInputSourceRecordsClosureAction()`**

```php
    public function userInputSourceRecordsClosureAction(): AiGenerateAction
    {
        return AiGenerateAction::make('userInputSourceRecordsClosure')
            ->userInput(UserInput::make()->fields([Textarea::make('count_hint')]))
            ->forModel(SeedCategory::class)
            ->prompt('Process.')
            ->sourceRecords(function (array $userInput) {
                $count = (int) ($userInput['count_hint'] ?? 1);

                return array_map(static fn (int $i): array => ['name' => "Row{$i}", 'slug' => "row-{$i}"], range(1, $count));
            })
            ->createRecords();
    }
```

- [ ] **Step 5: Add `noUserInputAction()`**

```php
    public function noUserInputAction(): AiGenerateAction
    {
        return AiGenerateAction::make('noUserInput')
            ->prompt('Static prompt.')
            ->outputSchema(fn ($schema) => ['x' => $schema->string()])
            ->handleUsing(function (array $data) {
                $this->handledData = $data;
            });
    }
```

- [ ] **Step 6: Verify the form schema includes the new actions**

`grep -n "function form" tests/Fixtures/GenerateFormComponent.php` — confirm the form registers actions by method name. If it uses `Action::make($name)` lookups, the new action methods are picked up automatically. If it explicitly lists actions, add the new ones.

(Most likely it's automatic via Filament's action-method discovery; verify by running tests in Task 7.)

- [ ] **Step 7: Pint the fixture**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && vendor/bin/pint tests/Fixtures/GenerateFormComponent.php
```

- [ ] **Step 8: Commit**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris
git add tests/Fixtures/GenerateFormComponent.php
git commit -m "test: fixtures for AiGenerateAction UserInput tests"
```

---

### Task 7: Write the test suite

**Files:**
- Create: `tests/Feature/AiGenerateActionUserInputTest.php`

Ten tests, grouped by what they cover. Each test uses Filament's `Livewire::test(...)->callAction('xxx', data: [...])` pattern that the existing `AiGenerateActionTest.php` uses.

- [ ] **Step 1: Create the test file**

Create `tests/Feature/AiGenerateActionUserInputTest.php`:

```php
<?php

use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;
use Statikbe\FilamentSolaris\Support\UserInput;
use Statikbe\FilamentSolaris\Testing\AiGenerateActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\GenerateFormComponent;
use Statikbe\FilamentSolaris\Tests\Fixtures\SeedCategory;

beforeEach(fn () => AiGenerateActionFake::reset());
afterEach(fn () => AiGenerateActionFake::reset());

it('exposes hasUserInput()=true when ->userInput() is set', function () {
    $action = AiGenerateAction::make('test')
        ->userInput(UserInput::make()->fields([Textarea::make('focus')]))
        ->outputSchema(fn ($schema) => ['x' => $schema->string()]);

    expect($action->hasUserInput())->toBeTrue()
        ->and($action->getUserInputFormSchema())->toHaveCount(1);
});

it('exposes hasUserInput()=false when ->userInput() is NOT set', function () {
    $action = AiGenerateAction::make('test')
        ->outputSchema(fn ($schema) => ['x' => $schema->string()]);

    expect($action->hasUserInput())->toBeFalse()
        ->and($action->getUserInputFormSchema())->toBe([]);
});

it('injects \$userInput into the ->prompt() closure (single-call path)', function () {
    AiGenerateAction::fake(['x' => 'ok']);

    Livewire::test(GenerateFormComponent::class)
        ->callAction('userInputSingleCall', data: ['focus' => 'SEO']);

    AiGenerateAction::assertCalledWithUserInput(fn (array $userInput) => $userInput['focus'] === 'SEO');
});

it('injects \$userInput into the ->handleUsing() closure', function () {
    AiGenerateAction::fake(['x' => 'ok']);

    Livewire::test(GenerateFormComponent::class)
        ->callAction('userInputSingleCall', data: ['focus' => 'conversational'])
        ->assertSet('handledData', [
            'data' => ['x' => 'ok'],
            'userInput' => ['focus' => 'conversational'],
        ]);
});

it('injects \$userInput into the ->sourceRecords() closure', function () {
    Schema::create('seed_categories', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('slug');
        $table->timestamps();
    });

    AiGenerateAction::fakeEach([
        ['name' => 'R1', 'slug' => 'r-1'],
        ['name' => 'R2', 'slug' => 'r-2'],
        ['name' => 'R3', 'slug' => 'r-3'],
    ]);

    Livewire::test(GenerateFormComponent::class)
        ->callAction('userInputSourceRecordsClosure', data: ['count_hint' => '3']);

    expect(SeedCategory::count())->toBe(3);

    Schema::dropIfExists('seed_categories');
});

it('injects \$userInput into the per-row ->prompt() closure in the records loop', function () {
    Schema::create('seed_categories', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('slug');
        $table->timestamps();
    });

    AiGenerateAction::fakeEach([
        ['name' => 'A', 'slug' => 'a'],
        ['name' => 'B', 'slug' => 'b'],
    ]);

    Livewire::test(GenerateFormComponent::class)
        ->callAction('userInputCreateRecordsLoop', data: ['focus' => 'brevity']);

    // Both per-row fake calls captured the same userInput
    AiGenerateAction::assertCalledWithUserInput(fn (array $userInput) => ($userInput['focus'] ?? null) === 'brevity');

    expect(SeedCategory::count())->toBe(2);

    Schema::dropIfExists('seed_categories');
});

it('appends the ## User context block to the single-call instruction', function () {
    $action = AiGenerateAction::make('test')
        ->prompt('Do the thing.')
        ->outputSchema(fn ($schema) => ['x' => $schema->string()]);

    $ref = new ReflectionMethod($action, 'resolveInstruction');
    $ref->setAccessible(true);

    $result = $ref->invoke($action, ['focus' => 'SEO']);

    expect($result)->toContain('Do the thing.')
        ->and($result)->toContain('## User context')
        ->and($result)->toContain('"focus": "SEO"');
});

it('appends ## User context BEFORE ## Current record in per-row instruction', function () {
    $action = AiGenerateAction::make('test')
        ->prompt('Process.')
        ->forModel(SeedCategory::class)
        ->sourceRecords([['name' => 'Tech', 'slug' => 'tech']])
        ->createRecords();

    $ref = new ReflectionMethod($action, 'resolveInstructionForRow');
    $ref->setAccessible(true);

    $result = $ref->invoke($action, ['name' => 'Tech', 'slug' => 'tech'], ['focus' => 'SEO']);

    $userCtxPos = strpos($result, '## User context');
    $currRecPos = strpos($result, '## Current record');

    expect($userCtxPos)->toBeInt()
        ->and($currRecPos)->toBeInt()
        ->and($userCtxPos)->toBeLessThan($currRecPos);
});

it('omits ## User context when \$userInput is empty or all-null', function () {
    $action = AiGenerateAction::make('test')
        ->prompt('Do the thing.')
        ->outputSchema(fn ($schema) => ['x' => $schema->string()]);

    $ref = new ReflectionMethod($action, 'resolveInstruction');
    $ref->setAccessible(true);

    $emptyResult = $ref->invoke($action, []);
    $allNullResult = $ref->invoke($action, ['x' => null, 'y' => '']);

    expect($emptyResult)->not->toContain('## User context')
        ->and($allNullResult)->not->toContain('## User context');
});

it('throws BadMethodCallException when ->withDefaultUserInput() is called on AiGenerateAction', function () {
    $action = AiGenerateAction::make('test')
        ->outputSchema(fn ($schema) => ['x' => $schema->string()]);

    expect(fn () => $action->withDefaultUserInput())->toThrow(BadMethodCallException::class);
});
```

- [ ] **Step 2: Run only the new tests — must all pass**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && vendor/bin/pest tests/Feature/AiGenerateActionUserInputTest.php
```

Expected: 10 passed.

If `callAction('xxx', data: [...])` syntax errors (Livewire/Filament version mismatch on named args), fall back to the positional form: `->callAction('xxx', ['focus' => 'SEO'])` — that's the equivalent if Filament's `callAction` signature is `($name, array $data = [])`. Look at how `tests/Feature/AiGenerateActionTest.php` calls actions with data for the project's idiomatic form.

- [ ] **Step 3: Run the full suite — 511 tests pass (501 + 10)**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && composer test
```

Expected: `Tests: 511 passed`.

- [ ] **Step 4: PHPStan + Pint**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && php -d memory_limit=512M vendor/bin/phpstan analyse && vendor/bin/pint tests/Feature/AiGenerateActionUserInputTest.php
```

Expected: PHPStan clean, Pint clean.

- [ ] **Step 5: Commit**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris
git add tests/Feature/AiGenerateActionUserInputTest.php
git commit -m "test: AiGenerateAction UserInput (modal, closure DI, auto-inject block)"
```

---

### Task 8: Documentation + CHANGELOG

**Files:**
- Modify: `documentation/ai-generate-action.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Add the "User Input" section to `documentation/ai-generate-action.md`**

Insert between "Provider, Model & Timeout" (ends ~line 291) and "Generation Options" (starts line 293):

```markdown
## User Input

Open a Filament modal before the action runs to collect runtime values
(steering text, file uploads, structured selections). Modal data is:

1. Auto-injected into the prompt as a `## User context` JSON block (top-level
   and per-row in the records loop, alongside `## Current record`).
2. Available as a `$userInput` named-arg in `->prompt()`, `->handleUsing()`,
   and `->sourceRecords()` closures (Filament-style DI — declare the arg
   to receive it; omit it if you don't need it).

### Spreadsheet-driven enrichment

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

### Free-text steering for single-call generation

```php
AiGenerateAction::make('generate-meta')
    ->userInput(UserInput::make()->fields([Textarea::make('focus')]))
    ->prompt(fn (array $userInput) => "Generate SEO meta. Focus: {$userInput['focus']}")
    ->outputSchema(fn ($schema) => [
        'title' => $schema->string(),
        'description' => $schema->string(),
    ])
    ->handleUsing(fn (array $data, array $userInput) => Cache::put('meta', $data));
```

### Notes

- File uploads in the modal surface as path strings in `$userInput['<field>']`
  — your `->sourceRecords()` or `->prompt()` closure parses from there.
  Sending uploaded files to the AI as Image/Audio/Document attachments is a
  planned follow-up (separate spec).
- `->withDefaultUserInput()` (pulling a preset's default modal config) is
  not available on `AiGenerateAction` — presets aren't yet a concept on
  this action. Calling it throws `BadMethodCallException`.
```

- [ ] **Step 2: Add the CHANGELOG entry**

Open `CHANGELOG.md`, find `## [Unreleased] > ### Added`. Add as the **first** bullet of that section (top of the list):

```markdown
- `AiGenerateAction` now supports `UserInput` modals: `->userInput(UserInput::make()->fields([...]))`
  opens a Filament modal before the action runs, with modal values (a)
  auto-injected into the prompt as a `## User context` JSON block (top-level
  and per-row in the records loop), and (b) available as a `$userInput`
  closure named-arg in `->prompt()`, `->handleUsing()`, and `->sourceRecords()`.
  Internal: `HasUserInput` trait split — `HasDefaultUserInput` extracted for
  the preset-default branch (only `HasPromptPipeline` consumers adopt it;
  `AiGenerateAction` adopts the slim `HasUserInput` only). `AiGenerateActionFake::recordCall`
  signature gains an `array $userInput = []` third arg, with a new
  `assertCalledWithUserInput(Closure)` helper.
```

- [ ] **Step 3: Commit**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris
git add documentation/ai-generate-action.md CHANGELOG.md
git commit -m "docs: AiGenerateAction UserInput section + changelog entry"
```

---

### Task 9: Trim spec 24's "Open questions" + final verification + simplifier pass

**Files:** none modified directly (simplifier may modify any of the touched files).

The spec was written in design-sketch mode and included an "Open questions" section. That section now belongs to the past — the implementation answered each one. Trim it.

- [ ] **Step 1: Trim the spec's resolved questions (optional)**

`grep -n "^## " specs/24-userinput-on-aigenerateaction.md` to see the section list. If the current spec has an "Open questions to settle when picked up" section left over from the deferred sketch, replace it with a one-line pointer to the implementation:

```markdown
## Resolved during impl

All open questions from the original deferred sketch were resolved during
the design pass. See `docs/superpowers/plans/2026-05-27-userinput-on-aigenerateaction.md`
for the implementation decisions.
```

(If the current spec file — written after brainstorming — doesn't have an "Open questions" section, skip this step. The brainstormed spec resolved them inline; the deferred-sketch wording may or may not survive in the current spec.)

- [ ] **Step 2: Full test suite**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && composer test
```

Expected: 511 pass.

- [ ] **Step 3: PHPStan with the recommended memory limit**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && php -d memory_limit=512M vendor/bin/phpstan analyse
```

Expected: `[OK] No errors`.

- [ ] **Step 4: Pint sweep**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && vendor/bin/pint
```

Expected: clean (any touch-ups are committed separately).

- [ ] **Step 5: Run laravel-simplifier on the changed surface**

Dispatch the `laravel-simplifier` agent (per `feedback_run_simplifier.md`) scoped to the diff vs `main`:

- `src/Concerns/HasUserInput.php`
- `src/Concerns/HasDefaultUserInput.php`
- `src/Actions/AiGenerateAction.php`
- `src/Testing/AiGenerateActionFake.php`

Tell the agent: "Suggest behaviour-preserving simplifications only; don't touch architecture or tests; report under 300 words." Review the suggestions; accept anything that simplifies without changing behaviour; defer architectural pushback to a follow-up.

- [ ] **Step 6: If simplifier made changes, re-verify and commit**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && composer test && php -d memory_limit=512M vendor/bin/phpstan analyse && vendor/bin/pint
git add -A && git commit -m "chore: simplifier pass on UserInput-on-AiGenerateAction"
```

- [ ] **Step 7: Review the full branch diff before handoff**

```bash
cd /Users/sten/PhpStormProjects/laravel-filament-solaris && git log --oneline main..HEAD && git diff main...HEAD --stat
```

Sanity-check expectations:
- ~9 commits (one per task, plus optional simplifier pass).
- `HasUserInput.php` net **-lines** (the preset-default bits extracted out).
- `HasDefaultUserInput.php` **new, ~30 lines**.
- `AiGenerateAction.php` net **+lines** (signature changes + auto-inject helper + closure DI).
- `AiGenerateActionFake.php` net **+lines** (third arg + new assertion helper).
- New test file ~120 lines, 10 tests.
- Fixture additions ~50 lines.
- Docs: ~50 lines added.

- [ ] **Step 8: Hand off to `superpowers:finishing-a-development-branch`**

Invoke the skill to present merge/PR/keep/discard options.

---

## Self-Review

**Spec coverage check (`specs/24-userinput-on-aigenerateaction.md`):**

- "New: `src/Concerns/HasDefaultUserInput.php`" → Task 1 ✅
- "Modified: `src/Concerns/HasUserInput.php`" (slim) → Task 1 ✅
- "Modified: `src/Concerns/HasPromptPipeline.php`" (adopt both traits) → Task 1, Step 3 ✅
- "Modified: `src/Actions/AiGenerateAction.php`" (adopt HasUserInput, delete override, schema/action callbacks, thread $userInput, closure DI, auto-inject) → Tasks 2, 3, 4 ✅
- "Modified: `src/Testing/AiGenerateActionFake.php`" (recordCall + new helper) → Task 5 ✅
- "New tests file" + fixtures → Tasks 6, 7 ✅
- "Documentation: `documentation/ai-generate-action.md` + CHANGELOG" → Task 8 ✅
- "Backward compat: existing tests stay green" → asserted explicitly in Tasks 1, 2, 3, 4, 5 ✅

**Naming consistency:**
- Trait: `HasDefaultUserInput` (consistent across Tasks 1 + spec).
- Method: `getDefaultUserInputFromBuilder()` — hook called from `HasUserInput::getUserInput()` (Tasks 1.1 and 1.2 match).
- Method: `appendUserContext(string $instruction, array $userInput): string` — defined once (Task 4 Step 3), called from two sites (Task 4 Step 2 + Step 4).
- Helper: `assertCalledWithUserInput(Closure $callback): void` (Task 5 + Task 7 — closure shape matches `fn (array $userInput): bool`).
- Block marker: `## User context` (consistent across spec, Task 4 helper, Task 7 tests).
- Parameter name: `$userInput` (consistent across all signature changes in Task 3 + 4 + 5).

**Placeholder scan:** No "TBD", "implement later", or "appropriate error handling" wording. Every code block is the actual code to write.

**Type consistency:** `array<string, mixed>` everywhere for `$userInput`. `recordCall` storage adds `'userInput' => $userInput` (Task 5 Step 1) and the assertion helper reads `$call['userInput'] ?? []` (Task 5 Step 2) — keys match.

**Trait split sanity:** `HasUserInput::getUserInput()` calls `getDefaultUserInputFromBuilder()` only when `method_exists($this, '...')` is true. On `AiFormAction` (via `HasPromptPipeline` which adopts both traits) → method exists → preset-default path live, identical to today. On `AiGenerateAction` (which adopts only `HasUserInput`) → method doesn't exist → returns null, no silent default. ✓

**Backward-compat verification:** every signature change defaults the new `$userInput` parameter to `[]`, every `recordCall` site keeps working with the default third arg, and Filament's `evaluate()` only injects parameters declared in the closure. No existing user code or test breaks.
