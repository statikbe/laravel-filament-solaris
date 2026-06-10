# Spec 30 — Queued runner

> Piece #3 of the **Queued Batch Execution** roadmap
> (`docs/superpowers/specs/2026-06-08-queued-batch-execution-design.md`).
> Builds on spec 28 (`BatchProcessor` + `BatchSink`) and spec 29 (persistence +
> events + `DatabaseBatchSink`, `solaris_batch_runs` / `solaris_batch_problems`),
> both merged. **Date:** 2026-06-10.

## 1. Goal

Make `AiGenerateAction`'s records loop **queue-native**: opt in with `->queued()`
and the run dispatches a `Bus::batch` of per-chunk jobs instead of looping in the
Filament request, so jobs of >~50 rows no longer hit request timeouts. The
**same `BatchProcessor` engine** runs on the worker against the already-merged
`DatabaseBatchSink`; a `Bus ->finally` finalizer reads the persisted outcome and
notifies.

**Out of scope (later pieces):** configurable completion-handler strategy (#4),
live-update spinner (#5), CSV failure report (#6). Phase 1 ships a built-in
completion notification only.

## 2. Two phases

- **Phase 1 — chunked records loop.** `->queued()` over `->sourceRecords()`:
  chunk → `Bus::batch` of `ProcessChunkJob × N` → `FinalizeRun`. No attachments.
- **Phase 2 — single-call / from-scratch (the "import a PDF of products" case).**
  No source rows: a `Bus::batch` of exactly one `ProcessChunkJob` carrying the
  pre-rendered prompt + **attachments** (first-class), reconciling by `_index`.
  Reuses every phase-1 mechanism — it is the degenerate one-job batch.

## 3. Constraints (what can and cannot be queued)

Queued requires **`forModel` + `createRecords()`/`updateRecords()`**. Handler-mode
(`handleUsing()`) and custom `->outputSchema()` both run a **closure** at
completion, which cannot cross the serialization boundary — they stay
**inline-only**. The records loop is already `forModel`-only, so its schema is
**data-derived** (`modelClass` + column arrays) and fully reconstructable from
scalars. `->queued()` on an unsupported configuration throws at
`validateConfiguration()` time with a clear message.

## 4. Architecture

```
->queued()  ──►  QueuedRunner (in-request — Livewire present)
                   ├─ resolve + chunk source → per-chunk row DESCRIPTORS
                   ├─ pre-render prompt string per chunk  (->prompt(fn($rows)…) runs HERE)
                   ├─ [phase 2] resolve attachments → File[]→toArray()
                   ├─ build serializable BatchRunConfig (schema scalars)
                   └─ Bus::batch([ ProcessChunkJob × N ])
                          ->allowFailures()->finally(FinalizeRun)->dispatch()
                                          │
                              ProcessChunkJob (worker — no Livewire, tries = 1)
                                   ├─ rebuild SolarisAgent from BatchRunConfig (no closure)
                                   ├─ [phase 2] rebuild File[] via File::fromArray()
                                   ├─ $agent->prompt($promptString, $files…)  (synchronous)
                                   └─ BatchProcessor::reconcile → DatabaseBatchSink
                                          │
                              FinalizeRun (Bus ->finally)
                                   └─ read run + problems → markCompleted,
                                      SolarisBatchCompleted, notify
```

The **inline path is unchanged and stays the default.** `->queued()` is opt-in.

## 5. Components

### 5.1 `->queued(bool|Closure $queued = true)` — on `AiGenerateAction`
Stores `$queued`. `executeRecordsLoop()` branches: inline (today's code) vs.
`QueuedRunner`. Resolved against `userInput` like the other fluent closures.

### 5.2 `BatchRunConfig` — `src/Support/Batch/BatchRunConfig.php`
`readonly` value object of **pure scalars**, no closures/live models:
`actionName`, `modelClass`, `onlyColumns`, `exceptColumns`, `columnHints`,
`columnEnums`, `identifierKey`, `writeTerminal`, `provider` (`Lab` enum|array|
string|null — all serializable), `model` (string|null), `timeout` (int|null),
`runId`. The worker rebuilds the schema
resolver from these via the existing `ModelSchemaResolver` (already data-derived —
no change) and rebuilds `writeRow` behaviour from `writeTerminal` + `modelClass`.

### 5.3 Row descriptors (the serialization rule)
Each chunk row is normalized **at dispatch** to the minimal descriptor the worker
needs to match the response and write back:

| Source row | Descriptor carried | Reconcile by | `writeRow` |
|---|---|---|---|
| Persisted model (`updateRecords`) | `[<pkColumn> => <pk>]` | pk | re-fetch `modelClass::find(pk)->update($attrs)` |
| Array / **unsaved** model (`createRecords`, from a source) | the array snapshot incl. `_index` | `_index` | `create($attrs)` (row ignored) |
| No rows (phase 2 / from-scratch) | — single job, no descriptors | `_index` | `create($attrs)` |

Worker rows are therefore **always plain arrays**, so `BatchProcessor` needs to
resolve a pk identifier from an array, not only from a `Model`.

### 5.4 `ProcessChunkJob` — `src/Jobs/ProcessChunkJob.php`
`ShouldQueue`, `public int $tries = 1`. Holds `BatchRunConfig $config`, `string
$prompt`, `array $rowDescriptors`, (phase 2) `array $attachments` (serialized).
`handle()`:
1. Rebuild the `SolarisAgent` + schema resolver from `$config`.
2. (phase 2) `File::fromArray()` each attachment.
3. Generate the response (synchronous `$agent->prompt(...)`; respects the AI fake
   when active so sync-queue tests replay canned responses — see §8).
4. `new BatchProcessor(identifierKey, generate, persist, new DatabaseBatchSink(runId))`
   and `->reconcile()` the single chunk. `persist` does create / re-fetch-by-pk
   update per §5.3. Discards are persisted by `DatabaseBatchSink` (type
   `discard`); the failure-channel logging the inline path does is optional here.

`SolarisBatchProgressed` (per-chunk event named in the umbrella but not yet built)
is dispatched here after each chunk's sink write, so live updates (#5) have a
substrate.

### 5.5 `FinalizeRun` — `src/Jobs/FinalizeRun.php`
Dispatched from `Bus ->finally` with `runId`. Reads `SolarisBatchRun` aggregate
counts + `solaris_batch_problems` (type `failure`/`discard`), `markCompleted()` (or a cancelled/
failed status if the batch was cancelled), fires `SolarisBatchCompleted`, and
sends the built-in completion notification to the run's `user_id`. No cross-job
in-memory matching — each job already self-reconciled and the sink already
aggregated atomically.

### 5.6 `QueuedRunner` — `src/Support/Batch/Runners/QueuedRunner.php`
Extracted so `AiGenerateAction` (already 1125 lines) does not grow. Given the
resolved source + the action's resolved config, it: starts the `SolarisBatchRun`
(reusing `startBatchRun`), chunks, pre-renders each chunk's prompt via the
action's `buildBatchInstruction`, builds descriptors, assembles `BatchRunConfig`,
and dispatches the `Bus::batch`. The inline path is **not** extracted in this
piece.

## 6. Required changes to existing code

1. **`BatchProcessor::identifierFor()`** — for the non-`_index` (pk) case, read
   the key from an array descriptor as well as from a `Model`:
   `$row instanceof Model ? $row->getKey() : ($row[$this->identifierKey] ?? null)`.
   Inline (Model) behaviour is unchanged; this *adds* array-descriptor support.
2. **`AiGenerateAction::writeRow()`** — the `WRITE_UPDATE` branch must accept an
   **array descriptor** (re-fetch `modelClass::find($row[$pk])`, record a failure
   if it is gone) in addition to a live `Model` (inline path). Strictly better:
   writes to current DB state; a row deleted mid-run becomes a recorded failure
   instead of a fatal.
3. **`validateConfiguration()`** — reject `->queued()` combined with handler-mode
   / custom `outputSchema` (closure can't serialize). Phase 1 additionally rejects
   `->queued()` + configured attachments with a "supported from phase 2" message.

## 7. Retry & idempotency (decision)

`ProcessChunkJob` defaults to **`tries = 1`, no DB transaction.** Rationale:
- Per-row write failures are already caught in `reconcile` and recorded — wrapping
  a chunk in one transaction breaks that isolation on **PostgreSQL** (a failed
  `INSERT` poisons the open transaction), and would discard the rows that *did*
  succeed.
- The only thing a transaction buys is retry-safety against a mid-write worker
  kill; `tries = 1` gives that for free by never retrying.
- `createRecords` is **not** idempotent under retry; `updateRecords` (update-by-pk)
  **is**. Documented as such.
- **Future opt-in (not built now):** `->transactionalChunks()` — savepoint-per-row
  atomic chunks that enable `tries > 1`.

Known limitation (documented): a chunk whose worker is killed mid-write may leave
rows the run counters never counted, since the sink records the chunk outcome once
at the end of `reconcile`. Rare; `tries = 1` keeps it from compounding.

## 8. Testing

- **`BatchProcessor` array-descriptor identifier** — unit, plain arrays, no DB.
- **`writeRow` re-fetch-by-pk** — update branch with an array descriptor; missing
  row → recorded failure.
- **`QueuedRunner` dispatch** — `Bus::fake()` + `assertBatched`; assert chunk
  count, pre-rendered prompts, descriptor shapes, `BatchRunConfig` scalars.
- **`ProcessChunkJob` execution** — `QUEUE_CONNECTION=sync` + `AiGenerateActionFake`
  (the fake's static state survives a sync-dispatched job in-process, so the job's
  response generator replays the canned response): assert rows written, failures
  persisted to `solaris_batch_problems` (type `failure`), run counters incremented.
- **`FinalizeRun`** — seed a run + problems, assert `markCompleted`,
  `SolarisBatchCompleted` dispatched, notification sent.
- **`validateConfiguration` guards** — `->queued()` + handler-mode / custom schema
  / (phase 1) attachments each throw.
- **Phase 2** — single-job batch over a from-scratch `createRecords` with a PDF
  attachment: `File::toArray()`→job→`File::fromArray()` round-trips and the agent
  receives the file.

## 9. Open items deferred to later pieces

- Completion-handler strategy + `->onCompletion()` (#4) — replaces §5.5's built-in
  notification with a configurable class.
- Live updates (#5) consume `SolarisBatchProgressed`/`SolarisBatchCompleted`.
- CSV failure report (#6) reads `solaris_batch_problems`.
- `->transactionalChunks()` (§7) if blind retry-duplication ever bites.