# Spec 28 — `BatchProcessor` extraction

> Piece #1 of the **Queued Batch Execution** roadmap
> (`docs/superpowers/specs/2026-06-08-queued-batch-execution-design.md`).
> Resolves review finding **N6**. Behaviour-preserving refactor of the
> `AiGenerateAction` records loop — no externally visible change, all current
> tests stay green.

## Goal

Extract the records-loop **engine** — chunking + per-batch reconciliation + write
— out of `AiGenerateAction` into a standalone, execution-agnostic
`BatchProcessor`, so that:

- the reconciliation table (matched / write-failed / silent-drop / hallucinated /
  duplicate) is **unit-testable with plain arrays** — no Livewire, DB, or AI;
- the real-vs-fake duplication (`processBatch` / `processFakeBatch`) collapses by
  **injecting the AI call as a callable**;
- the engine exposes the exact seams (`generateResponse`, `persistRecord`,
  `BatchSink`) that the queued runner (piece #3) and DB sink (piece #2) plug into
  later — **without reshaping the engine**.

## Non-goals

Queueing, persistence, events, the DB sink, completion handlers — those are pieces
#2–#6. This spec ships only the engine + an in-memory sink that reproduces today's
in-request behaviour. The single-call path (`handleSingleCallResponse`,
generate-from-scratch create) is **out of scope** and unchanged.

## The engine

```php
namespace Statikbe\FilamentSolaris\Support;

final class BatchProcessor
{
    /**
     * @param  string  $identifierKey                              resolved once (e.g. '_index' or the PK column)
     * @param  Closure(array<int, mixed>): BatchResponse  $generateResponse   real or faked; throws BatchGenerationException on an AI-call failure
     * @param  Closure(mixed, array<string, mixed>): void  $persistRecord     write strategy: create/update the record
     */
    public function __construct(
        private string $identifierKey,
        private Closure $generateResponse,
        private Closure $persistRecord,
        private BatchSink $sink,
    ) {}

    /** @param  iterable<int, array<string, mixed>|Model>  $rows */
    public function process(iterable $rows, int $batchSize): void
    {
        foreach ($this->chunk($rows, $batchSize) as $batch) {
            try {
                $outcome = $this->reconcile($batch, ($this->generateResponse)($batch));
            } catch (BatchGenerationException $e) {
                $outcome = new BatchOutcome(0, $this->markFailed($batch, $e->getMessage()), []);
            }

            $this->sink->record($outcome);
        }
    }

    /** Public so it can be unit-tested directly with a canned BatchResponse. */
    public function reconcile(array $batch, BatchResponse $response): BatchOutcome { /* … */ }

    // private: chunk(), markFailed()
}
```

### `reconcile()` — the logic being lifted (semantics unchanged from spec 27 + #22 + later fixes)

1. Build an `identifier → input row` lookup over `$batch`.
2. For each output record:
   - identifier already consumed → **discarded** (`duplicate`);
   - identifier null / not in lookup → **discarded** (`hallucinated`);
   - else: strip the identifier key, call `persistRecord($sourceRow, $attributes)`;
     success → `succeeded++`; throw → a `FailedRecord` (`reason: 'write error: …'`,
     `input: $sourceRow`). **No per-row `report()`** (#22).
3. AI-reported `failed[]` → `FailedRecord`s, re-attaching `input` from the lookup
   when the identifier matches.
4. Input rows never echoed → `FailedRecord` (`reason: 'no response from AI'`,
   `input: $sourceRow`) — silent drops.

The engine does **no logging and reads no config**: hallucinated/duplicate records
become **structured `discarded` entries in the `BatchOutcome`** instead of inline
`logToFailureChannel()` calls. Whoever owns the sink decides what to log/persist —
which is how the #22 policy is preserved (see wiring below) and what makes the
engine pure.

## New / changed value objects

```php
final readonly class BatchOutcome
{
    /**
     * @param  array<int, FailedRecord>     $failures
     * @param  array<int, DiscardedOutput>  $discarded
     */
    public function __construct(
        public int $succeeded,
        public array $failures,
        public array $discarded,   // NEW
    ) {}
}

final readonly class DiscardedOutput
{
    public function __construct(
        public string $kind,                  // 'hallucinated' | 'duplicate'
        public array $record,                 // the spurious output record
        public string $reason,                // ready-to-log message
    ) {}
}
```

`BatchGenerationException` (new, `Support/`): thrown by a `generateResponse`
closure when the AI call for a batch fails (network/timeout/empty/fake-simulated).
The processor catches **only** this — see Behaviour notes.

## The sink seam

```php
interface BatchSink
{
    public function record(BatchOutcome $outcome): void;
}

final class InMemoryBatchSink implements BatchSink   // the only impl this spec ships
{
    // accumulates succeeded + failures + discarded across batches;
    // exposes succeeded(): int, failures(): array, discarded(): array
}
```

Piece #2 adds `DatabaseBatchSink`; piece #5's progress events become something a
sink *also* emits. The interface does not change.

## How `AiGenerateAction` wires it (`executeRecordsLoop`)

```php
$rows       = $this->resolveRecordsSource($userInput);
$attachments= $this->resolveAttachments($userInput);
$collector  = new InMemoryBatchSink;

$processor = new BatchProcessor(
    identifierKey:    $this->resolveIdentifierKey(),
    generateResponse: $this->makeResponseGenerator($userInput, $attachments, /* provider/model/timeout */),
    persistRecord:    fn (mixed $source, array $attributes) => $this->writeRow($source, $attributes),
    sink:             $collector,
);

$processor->process($rows, $this->resolveBatchSize($userInput));

foreach ($collector->discarded() as $d) {            // #22 anomaly logging, now driven by the outcome
    $this->logToFailureChannel($d->reason);
}
$this->finishBatchRun($collector->succeeded(), $collector->failures(), $userInput);
```

`makeResponseGenerator()` returns the `generateResponse` closure and is where the
**real-vs-fake branch lives** (collapsing `processBatch`/`processFakeBatch`):

- **real:** `buildBatchInstruction` → `SolarisAgent->prompt` via `executeAiCall`;
  `null` → `throw new BatchGenerationException('AI call error')`; else
  `BatchResponse::fromArray($response->toArray())`.
- **fake:** resolve the instruction (so prompt-closure errors still surface) →
  `recordCall(...)`; then if `shouldSimulateError` → `dispatchFakeResponseFailed`
  + `throw new BatchGenerationException($fake->getErrorMessage())`; else
  `dispatchFakeResponseReceived` + `BatchResponse::fromArray($fake->getResponse())`.
  (Same recordCall/event ordering as today's `processFakeBatch`.)

**Removed from `AiGenerateAction`** (moved into `BatchProcessor`): `chunkRows`,
`reconcileBatch`, `markBatchFailed`, `processBatch`, `processFakeBatch`.
**Kept:** `resolveIdentifierKey`, `buildBatchInstruction` + `append*`, `writeRow`,
`finishBatchRun`, `logToFailureChannel`, all config resolution.

## Behaviour notes (one deliberate, verified change)

Today `executeRecordsLoop` catches **every** `Throwable` per batch (report +
mark-failed + continue) and re-throws `AiGenerateActionFakeException`. The
processor instead catches **only `BatchGenerationException`** (→ mark-failed +
continue); any other throwable — a genuine bug, or `AiGenerateActionFakeException`
from a test-config error such as an exhausted `fakeEach` queue — **propagates and
aborts the run**.

This keeps the engine decoupled from test infrastructure (it owns
`BatchGenerationException` and knows nothing about fakes), aligns with the #22
policy (genuine exceptions stay loud rather than being swallowed into a counted
failure), and — verified against the suite — breaks no test: today's catch-all
path is only ever exercised by AI-call failures, which now arrive as
`BatchGenerationException`. The AI-call error is still **not** double-reported
(`executeAiCall` already handles its own reporting), matching current behaviour.

Everything else is identical: per-row write errors → `FailedRecord` (no report);
silent drops, hallucinated/duplicate handling, the manifest log, `onPartialFailure`,
the summary notification, and the `failure_logging` gating all preserved.

## Testing

- **New `tests/Unit/Support/BatchProcessorTest.php`** — pure, no harness. Construct
  with an `identifierKey`, a stub `generateResponse` returning a canned
  `BatchResponse` (or throwing `BatchGenerationException`), a **recording**
  `persistRecord`, and an `InMemoryBatchSink`. Cover: happy path + which rows were
  persisted; per-row write error → `FailedRecord`; silent drop; hallucinated →
  `discarded`; duplicate → `discarded`; string/int identifier coercion;
  AI-call failure → whole batch `FailedRecord`s; multi-batch chunking
  (array, Collection, Builder→`get()` shapes via `chunk`).
- **`tests/Unit/Support/BatchOutcomeTest.php`** (or extend existing) — the new
  `discarded` field round-trips.
- **Existing feature tests stay green unchanged** — they verify the end-to-end
  wiring; the anomaly-channel tests still pass because the action still calls
  `logToFailureChannel` (now from `$collector->discarded()`).
- Full suite + PHPStan L5 + Pint clean. Run the simplifier on the changed surface.

## File structure

**New:** `src/Support/BatchProcessor.php`, `src/Support/Contracts/BatchSink.php`,
`src/Support/InMemoryBatchSink.php`, `src/Support/DiscardedOutput.php`,
`src/Support/BatchGenerationException.php`, `tests/Unit/Support/BatchProcessorTest.php`.
**Modified:** `src/Actions/AiGenerateAction.php` (wire the processor, delete the
moved methods, add `makeResponseGenerator`), `src/Support/BatchOutcome.php` (add
`discarded`), `documentation/ai-generate-action.md` + `CHANGELOG.md` (internal
note; the public API is unchanged, so this is light).
