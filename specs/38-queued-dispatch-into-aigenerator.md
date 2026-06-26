# 38 — Queued dispatch into AiGenerator (piece 3)

> **Status:** ✅ Implemented on `feature/records-loop-into-aigenerator` (suite 669, PHPStan + Pint clean).
> Piece 3 of the AiGenerator arc (umbrella: `36-ai-generator-service.md`).
> **Date:** 2026-06-26.
> **Depends on:** piece 2 (records-loop + from-scratch in `AiGenerator`).
> **Closes:** the arc's core — `AiGenerator` owns single + batch, inline + queued.

## Implementation (2026-06-26)

- **Step 1** — `ProcessChunkJob` writeRow/schemaResolver → `RecordWriter` +
  `RecordsSchemaBuilder` (last schema/write-back dup gone).
- **Step 2** — `AiGenerator::runQueued(): SolarisBatchRun`; moved `buildRunConfig`,
  `serializeAttachments` (protected), `buildChunkDescriptors`, renderPrompt into the
  service; dispatch via `QueuedRunner`. `createTrackedRun(?int $total)`.
- **Step 3** — `executeRecordsLoop`/`executeFromScratchCreate` choose the terminal
  (`runQueued()` + started notice vs `runInline()`); `HasQueuedExecution` down to
  `queued`/`isQueued`/`sendQueuedStartedNotification`; action `startBatchRun` deleted
  (service creates runs via `SolarisBatchRun::start`). Two reflection tests repointed.

**Follow-ups:** headless `documentation/` for the batch + queued surface (deferred to
arc end); Option 2 service-native `AiGenerator::fake()` (deferred). Then the arc's
*next* phase: `AiFormAction` routes its AI call through the service + parity ports
(spec 35 Part A: `sanitize`, presets, `tools`), then knxcou.

---

## Goal

Add `AiGenerator::runQueued(): SolarisBatchRun` and move the queued-dispatch
machinery (`HasQueuedExecution`'s `dispatchQueuedRun` / `dispatchQueuedSingleCall`
/ `buildRunConfig` / `serializeAttachments` / `buildChunkDescriptors`) into the
service. The action's `->queued()` becomes a terminal choice on the same
configured generator: build the generator, then call `runQueued()` instead of
`runInline()`. Point the queued worker (`ProcessChunkJob`) at the shared
collaborators, killing the last schema/write-back duplication.

This mirrors piece 2 exactly: inline and queued are now two terminals on one
configured `AiGenerator`, differing only in *where* the AI call happens (in-request
vs on the worker).

---

## What moves into AiGenerator

- **`runQueued(): SolarisBatchRun`** — infers records-loop vs from-scratch from
  `sourceRecords` (same inference as `runInline`). Queued **always tracks** (the
  run row is the only place per-chunk outcomes aggregate), so it creates the run
  via `SolarisBatchRun::start()` regardless of `trackBatchRuns`.
- **`buildRunConfig(SolarisBatchRun): BatchRunConfig`** — snapshots config to
  scalars. `AiGenerator` already holds modelClass/columns/identifier/writeTerminal/
  provider/model/timeout/options.
- **`serializeAttachments(File[]): array`** — the queue serialisation guard
  (reject `local-*`). Filament-free.
- **`buildChunkDescriptors(chunk): array`** — positional snapshot (create) or
  `[pk]` (update). Uses `resolveIdentifierKey` + Model; Filament-free.
- **renderPrompt** — `fn($chunk) => (new BatchPromptBuilder(...))->build($this->prompt, $chunk, $this->userInput)`.
  The action already pre-wraps its Filament-DI instruction closure into the plain
  `fn($rows,$userInput)` shape (piece 2), so prompts render identically.
- Dispatch via the existing `QueuedRunner` (`dispatch` chunked / `dispatchSingleCall`
  from-scratch). Run creation already shared via `SolarisBatchRun::start()`.

## What stays on the action (`HasQueuedExecution`)

- `queued(bool|Closure)` + `isQueued()` (Filament closure evaluation).
- `sendQueuedStartedNotification()` (UI).
- The queued validation guards in `validateConfiguration()` (forModel + create/update).

## Action delegation

`execute()` simplifies to two write-terminal branches, each choosing the terminal:

```php
if ($this->source !== null)        { $this->executeRecordsLoop($userInput); return; }   // inline | queued
if ($this->writeTerminal !== null) { $this->executeFromScratchCreate($userInput); return; } // inline | queued
// handler / custom-schema single call (unchanged)
```

```php
protected function executeRecordsLoop(array $userInput): void
{
    $generator = $this->makeBatchGenerator($userInput);
    if ($this->isQueued($userInput)) {
        $generator->runQueued();
        $this->sendQueuedStartedNotification();
        return;
    }
    $generator->runInline();
}
```

`executeFromScratchCreate` gains the same `isQueued` branch (its inline arm keeps
the `BatchGenerationException` → notification catch). The separate
`dispatchQueuedSingleCall` branch in `execute()` is removed — from-scratch queued
now flows through `executeFromScratchCreate`.

## The fake — unchanged

For queued, the AI call happens on the **worker** (`ProcessChunkJob`), which
already replays `AiGenerateActionFake` itself (works under the sync queue in
tests). The action's injected `->responseGenerator()` override (piece 2's seam) is
only consulted by `runInline`'s `InlineRunner`, so it is simply unused on the
queued path — no serialisation concern (the closure never leaves the request; only
the scalar `BatchRunConfig` + pre-rendered prompts travel). The e2e
`->queued()` + sync-queue + `fakeEach` tests pass unchanged.

## Worker dedup (`ProcessChunkJob`)

- `writeRow()` → `(new RecordWriter($modelClass, $config->writeTerminal))->write(...)`.
- `schemaResolver()` → `(new RecordsSchemaBuilder)->build($schema, $modelClass, $config->identifierKey, only, except, hints, enums)`.

Removes the worker's hand-rolled copies; `ProcessChunkJob` keeps only its
worker-specific orchestration (sink, progress event, from-scratch loop).

---

## Decomposition (TDD, green at each step)

1. **Worker dedup** — point `ProcessChunkJob` at `RecordWriter` +
   `RecordsSchemaBuilder`. Isolated, lowest risk. (Existing `QueuedRunnerTest`
   pins behaviour.)
2. **`AiGenerator::runQueued`** — move `buildRunConfig` / `serializeAttachments` /
   `buildChunkDescriptors` / renderPrompt into the service; dispatch via
   `QueuedRunner`. Headless test: `->queued()`-equivalent via the sync queue.
3. **Action delegates** — `executeRecordsLoop` / `executeFromScratchCreate` gain the
   `isQueued → runQueued()` branch; strip the moved methods from
   `HasQueuedExecution` (keep `queued`/`isQueued`/notification); drop the
   `dispatchQueuedSingleCall` branch from `execute()`. The 666-test suite pins it.

---

## Decisions
- **`GenerationOptions`**: the service does **not** use the `HasGenerationOptions`
  trait — it keeps its own `->options(GenerationOptions)` setter and `buildRunConfig`
  reads `$this->options`. The trait stays on `AiGenerateAction` + `HasPromptPipeline`
  (the `AiFormAction` pipeline); when `AiFormAction` later routes through the service
  the thin-abstraction concern resolves itself. No change now.
- **`serializeAttachments`**: `protected` on `AiGenerator` (per Sten) — only the
  queued path needs it, but protected leaves room for a subclass/override.
