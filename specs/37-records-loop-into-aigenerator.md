# 37 — Records-loop + write-back into AiGenerator (piece 2)

> **Status:** In progress on `feature/records-loop-into-aigenerator`.
> Piece 2 of the AiGenerator arc (umbrella: `36-ai-generator-service.md`).
> **Date:** 2026-06-25.
> **Depends on:** piece 1 (single-call `AiGenerator::runInline()`, merged).
> **Followed by:** piece 3 (queued dispatch into the service — `runQueued`).

## Implementation status (2026-06-26) — functionally complete

**Done (committed, suite 664 green, PHPStan + Pint clean):**
1. Collaborators extracted + action delegates: `RecordsSchemaBuilder`,
   `BatchPromptBuilder`, `RecordWriter`, `Runners/InlineRunner`.
2. `AiGenerator` batch API (`forModel`/`only`/`except`/`columnHints`/
   `columnEnums`/`sourceRecords`/`count`/`createRecords`/`updateRecords`/
   `batchSize`/`promptContextColumns`/`userInput`/`trackBatchRuns`/`onCompletion`/
   `withFailureReport`); `runInline(): GenerationResult|BatchSummary`. Owns prompt
   assembly, schema, write-back, run creation/tracking, the real per-batch agent
   call + events, InlineRunner orchestration. `source()` → `eventSource()`.
3. **Records-loop:** `executeRecordsLoop` → `makeBatchGenerator()->runInline()`.
4. **From-scratch `count()`:** headless `forModel + count + createRecords`
   (`runFromScratch`); from-scratch seed prompt → `BatchPromptBuilder::fromScratch`.
   Action's path → `executeFromScratchCreate` → `makeFromScratchGenerator()`.
   Deleted the dead write loop in `handleSingleCallResponse` (handler/custom-schema
   only remain) + orphaned `writeRow()`/`finishBatchRun()`.
5. Fake plugs in via `->responseGenerator()` (Option 1 seam) for both paths;
   `AiGenerateActionFake` surface unchanged.
6. Const-alias cleanup: dropped `WRITE_CREATE`/`WRITE_UPDATE` (→ `RecordWriter::`)
   and `RECORDS_KEY`/`FAILED_KEY` (→ `BatchResponse::`).

**Follow-ups (not blocking piece 2):**
- Extend the headless `documentation/` for the batch surface.
- The action's `startBatchRun` now serves only the queued path → piece 3 absorbs
  it (queued dispatch into the service, `runQueued`). The queued worker still has
  its own schema/write-back; piece 3 points it at these collaborators.
- Option 2 service-native `AiGenerator::fake()` (deferred; see fake section).

---

## Goal

Move the **inline records-loop + write-back** out of `AiGenerateAction` and into
the headless `AiGenerator` service, so a batch generation is callable without a
Filament action:

```php
AiGenerator::make()
    ->forModel(Product::class)
    ->sourceRecords($products)        // pre-resolved rows
    ->updateRecords()
    ->batchSize(20)
    ->runInline();                    // → BatchSummary
```

The action keeps its public fluent API and becomes a **thin translator**: it
evaluates its Filament-DI closures, hands the service plain values plus one
pre-wrapped instruction closure, and calls `runInline()`. The 634 tests guard the
behaviour through the move.

The queued path (`HasQueuedExecution` + worker) is **untouched** in this piece;
piece 3 points the worker at the same collaborators this piece establishes.

---

## What moves (decided with Sten: layers 2, 3, 4 all move)

The inline loop in `AiGenerateAction::executeRecordsLoop()` is four layers:

| Layer | Today (in the action) | Piece 2 |
|---|---|---|
| 1. Filament-closure evaluation | `$this->evaluate()` on `source`/`instruction`/`batchSize`/`tracked` | **stays action-side** (irreducibly Filament) |
| 2. Prompt assembly | `buildBatchInstruction`, `appendRecordsBlock`, `appendBatchInstructions`, `enrichBatchWithIdentifier`, `buildContextForRow` | **moves** → `BatchPromptBuilder` |
| 3. Schema + write-back | `resolveSchemaResolver` (records/failed wrapper), `resolveIdentifierKey`, `writeRow` | **moves** → `RecordsSchemaBuilder` + `RecordWriter` |
| 4. Run orchestration | sink build, `startBatchRun`, `BatchProcessor::process`, discarded logging, `markCompleted` + event, `finishBatchRun` (failure report + completion handlers) | **moves** → `InlineRunner` |

Layers 2–4 are already Filament-free; only layer 1 touches `$this->evaluate()`.
Moving 2–4 is what makes the service a *usable* batch engine rather than a hollow
orchestrator — a headless caller can't supply a Filament `responseGenerator`
closure, so prompt assembly and write-back have to live in the service.

---

## New collaborators (the decomposition of layers 2–4)

`AiGenerator` would balloon past readability if it absorbed all three layers
directly. Instead it **delegates** to small, Filament-free, independently-testable
collaborators — which also become the shared homes piece 3's worker reuses
(killing the schema/write-back duplication the umbrella spec calls out).

- **`Support/Batch/RecordsSchemaBuilder`** — wraps a model's resolved properties
  (from the existing `ModelSchemaResolver`) in the `records[]` + `failed[]`
  envelope, including the synthetic identifier column (`_index` or PK echo).
  Reused by: forModel single-call (piece 1 path, re-pointed), batch, worker.
- **`Support/Batch/BatchPromptBuilder`** — Filament-free prompt assembly: takes a
  *resolved* instruction (string | `View` | plain `Closure(array $rows, array $userInput)`),
  plus `userInput`, the batch, `identifierKey`, `promptContextColumns`,
  `modelClass`, and emits the full per-batch instruction (`## Records` block,
  `## User context`, batch instructions, identifier enrichment). Reused by:
  inline batch (now), queued dispatch render (piece 3).
- **`Support/Batch/RecordWriter`** — create/update write-back given `modelClass` +
  write terminal + identifierKey. This is the `persistRecord` closure today's
  `BatchProcessor` already takes. Reused by: inline (now), worker (piece 3).
- **`Support/Batch/Runners/InlineRunner`** — the inline run lifecycle, mirroring
  the existing `QueuedRunner`: build the sink(s), `startBatchRun` (when tracked),
  drive `BatchProcessor`, log discarded, `markCompleted` + `SolarisBatchCompleted`,
  run `finishBatchRun` (failure report + completion handlers), return a
  `BatchSummary`.

> These boundaries are the recommended structure. If spec review judges any one
> too granular, it can fold into a private method without changing the seam — the
> implementation plan finalizes the split.

---

## AiGenerator batch API (additions)

Single-call setters from piece 1 stay. New batch-facing setters:

- `forModel(class)`, `only(array)`, `except(array)`, `columnHints(array)`,
  `columnEnums(array)` — model-aware schema. The service takes the **bulk map**
  form (`columnHints(['name' => 'hint', …])`); the action already holds these as
  maps, so it passes them straight through. (The action keeps its singular
  per-column `columnHint(col, hint)` fluent setters as the public surface.)
- `sourceRecords(iterable $rows)` — **pre-resolved** rows (the action evaluates
  its `source` closure first; headless callers pass a Collection/array/Builder
  result directly).
- `count(int)` — from-scratch record count (forModel + createRecords, no source).
- `createRecords()` / `updateRecords()` — write terminal.
- `batchSize(int)`, `promptContextColumns(array)`.
- `userInput(array)` — the resolved modal values (action-side modal; service just
  receives the array for the `## User context` block).
- `trackBatchRuns(bool)`, `onCompletion(array<class-string>)`,
  `withFailureReport(bool)` — tracking + completion config (action resolves the
  config-vs-override precedence first and passes the final booleans / class list).

`prompt()` widens from `string` to `string|View|Closure`. A `Closure` is invoked
with **plain `value()` semantics** receiving `['rows' => …, 'userInput' => …]`,
never Filament's `evaluate()`. The action wraps its Filament-DI instruction
closure as `fn (array $rows, array $userInput) => $this->evaluate($this->instruction, compact('rows', 'userInput'))` and passes that wrapper in — so the service stays Filament-free.

**Naming:** the existing `AiGenerator::source(string $name, ?string $class)` sets
the *event-source labels* and now collides conceptually with `sourceRecords()`.
Rename it `eventSource()` (only the action calls it; low-risk, do via
`ide_refactor_rename`).

### Return-type inference

`runInline(): GenerationResult | BatchSummary` — inferred from the terminal, not a
method-name flag:

- terminal returns to caller (`handleUsing` / custom `outputSchema`) → single AI
  call → **`GenerationResult`** (piece 1 behaviour; action runs the handler).
- write terminal (`createRecords` / `updateRecords`) → service does write-back +
  tracking + finish → **`BatchSummary`**.

### Execution shapes the service now owns

| Shape | Source | Path |
|---|---|---|
| custom schema + `handleUsing`, single call | — | `GenerationResult` (piece 1) |
| forModel + `handleUsing`, single call | — | `GenerationResult` (piece 1); action runs handler with a `BatchResponse` |
| forModel + `createRecords`, **from scratch** (`count` N) | none | single call → write all rows → `finishBatchRun` → `BatchSummary` |
| forModel + create/update + `sourceRecords` | rows | `BatchProcessor` reconcile → write → `finishBatchRun` → `BatchSummary` |

The from-scratch create path (row 3, driven by `count(N)`) is **in scope for piece
2**. It reuses the piece-1 single call internally (fires
`SolarisResponseReceived/Failed`), then the same write-back + finish as the records
loop. It currently lives in the action's `handleSingleCallResponse` createRecords
branch and moves with the rest.

---

## The fake seam (Option 1 — chosen)

The fake's assertion surface **straddles the seam**: `assertCalledWithBatch` /
`…WithUserInput` / `…WithAttachments` are about the AI call (service-side after the
move), while `assertHandledWith` is about the `handleUsing` handler (action-side —
the service never invokes it). So we relocate the fake **without splitting it**:

- `AiGenerator`'s batch path exposes **one internal extension point** — the
  per-batch *"built instruction → `BatchResponse`"* step is an injectable closure
  defaulting to the real agent call. The service always builds the prompt (via
  `BatchPromptBuilder`) and the enriched rows first, then hands them to that
  closure. On a real run it's the service's own closure: it calls the agent and
  fires `SolarisResponseReceived/Failed`.
- Under a fake, `AiGenerateAction` injects a closure that returns the canned
  response, records the call on `AiGenerateActionFake` (name / batch / userInput /
  attachments), and fires the fake events — exactly today's
  `makeResponseGenerator` fake branch, lifted action-side almost verbatim.
- `AiGenerateActionFake`'s public API is **unchanged** (`fake`, `fakeEach`,
  `assertCalledWithBatch`, `assertHandledWith`, …), so the 634 tests don't move.
- The service knows nothing about `AiGenerateActionFake` — only about a generic
  "produce a batch response" closure. Because the service still builds the prompt
  even under the fake, prompt-closure errors still surface in tests.

### Deferred follow-up — Option 2 (service-native fake)

A canonical `AiGenerator::fake()` (so headless callers get a fake too) is
**deferred to its own piece**. It can't merely relocate the fake — because
`assertHandledWith` is action-side it would have to *split* the fake across an
`AiGeneratorFake` (AI-call records + responses/queue/errors) and a slimmed
`AiGenerateActionFake` (handler records), reconcile a single source of truth for
call records, re-point ~8 assertion helpers, and re-validate all 634 tests. The
risk lives in that bridging, not in building the fake. No current caller needs it
(knxcou v1 uses real runs + `updateOrCreate`). Option 1's injected-response seam
is exactly the foundation Option 2 would build on, so deferring costs nothing.

---

## The action after the move

`AiGenerateAction::executeRecordsLoop()` collapses to: evaluate Filament closures
→ configure an `AiGenerator` → (inject the fake closure if a fake is active) →
`runInline()`. Roughly:

```php
$generator = AiGenerator::make()
    ->forModel($this->modelClass)
    ->only($this->onlyColumns)->except($this->exceptColumns)
    ->columnHints($this->columnHints)->columnEnums($this->columnEnums)   // mirrored setters
    ->sourceRecords($this->resolveRecordsSource($userInput))             // closure evaluated here
    ->prompt($this->wrapInstructionClosure($userInput))                  // Filament DI wrapped to plain
    ->userInput($userInput)
    ->{$this->writeTerminal === self::WRITE_UPDATE ? 'updateRecords' : 'createRecords'}()
    ->batchSize($this->resolveBatchSize($userInput))
    ->promptContextColumns($this->promptContextColumns)
    ->provider($provider, $model)->timeout($timeout)->options($options)
    ->attachments($this->resolveAttachments($userInput))
    ->trackBatchRuns($this->isTracked($userInput))
    ->onCompletion($this->resolveCompletionHandlers())
    ->withFailureReport($this->resolveAttachFailureReport())
    ->eventSource($this->getName(), static::class)
    ->forLivewire($this->getLivewire())->forUser(auth()->user());

$summary = $generator->runInline();   // BatchSummary
```

**Stays on the action:** the `userInput` modal, all Filament-closure evaluation,
notifications (started + error), `liveBatchUpdates()`, user-facing validation
messages, the fake, and (for handler/custom-schema terminals) running the handler
on the returned `GenerationResult`.

---

## Relocation strategy (behaviour-preserving)

1. **Extract collaborators first**, each behind its own tests, by lifting the
   existing private methods verbatim (`buildBatchInstruction` & friends →
   `BatchPromptBuilder`; `writeRow` → `RecordWriter`; the records/failed wrapper →
   `RecordsSchemaBuilder`; `executeRecordsLoop` body → `InlineRunner`). No
   behaviour change; the action delegates to them while keeping its method names.
2. **Add the batch API + `InlineRunner` wiring to `AiGenerator`**, with the
   injected response-generator seam.
3. **Re-point the action** to configure + call `AiGenerator`, deleting the
   now-duplicated private methods. The fake closure is the only batch logic that
   stays action-side.
4. Run the full suite at each step; it must stay green throughout (TDD —
   collaborator tests added before extraction; integration behaviour pinned by the
   existing 634).

The action **keeps its public fluent API** (`->forModel()`, `->createRecords()`,
`->queued()`, …) for backward compatibility and Filament-closure support.

---

## Decisions

**Locked**
- Layers 2, 3, 4 all move into the service (Sten, this session).
- Collaborators: `RecordsSchemaBuilder`, `BatchPromptBuilder`, `RecordWriter`,
  `InlineRunner` (folding allowed if review finds one too granular).
- Fake: Option 1 (injected response-generator seam; `AiGenerateActionFake`
  surface unchanged). Option 2 (service-native fake) is a tracked follow-up.
- `runInline()` returns `GenerationResult | BatchSummary`, inferred from the terminal.
- Rename `AiGenerator::source()` → `eventSource()` to free the name for `sourceRecords()`.
- `count()` (from-scratch create) is **in scope for piece 2** — it shares the
  write-back + finish path.
- Collaborator granularity as proposed (`RecordsSchemaBuilder`, `BatchPromptBuilder`,
  `RecordWriter`, `InlineRunner`) — confirmed, no folding.
- The service takes **bulk** `columnHints(array)` / `columnEnums(array)`; the
  action keeps its singular `columnHint(col, hint)` public setters and passes the
  assembled maps through.