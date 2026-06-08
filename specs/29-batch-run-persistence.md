# Spec 29 — Batch run persistence + events

> Piece #2 of the **Queued Batch Execution** roadmap
> (`docs/superpowers/specs/2026-06-08-queued-batch-execution-design.md`).
> Builds on spec 28 (`BatchProcessor` + `BatchSink`). Introduces the first
> migrations in the package.

## Goal

Make a records-loop run a **persisted, observable first-class thing** — a
`solaris_batch_runs` row with status + counts and a `solaris_batch_problems` row
per **failed input row *or* discarded output** — plus `SolarisBatchStarted` /
`SolarisBatchCompleted` events. Wire it into the **in-request (sync) path as an
opt-in** (`->tracked()`, default off), so the data layer has an immediate, tested
consumer and a full problem report (piece #6) isn't gated behind the full queue.
The queued runner (piece #3) reuses the exact same run + sink + events.

The `BatchProcessor` (spec 28) is unchanged **except a one-word honesty rename**:
`DiscardedOutput.kind` `'hallucinated'` → `'unmatched'` (see §Spec-28 touch).

## Non-goals

Queueing, the Bus jobs, completion-handler classes, live updates, the CSV report
itself (we persist its *data*, piece #6 renders it). Per-batch
`SolarisBatchProgressed` events are deferred to piece #5. Run pruning is deferred.

## Why one "problems" table (not just failures)

A records-loop run produces two kinds of trouble, and an operator wants to see
**both** to know what went wrong:

- **failures** — an *input row* that didn't succeed (silent drop, AI-reported
  failure, write error, whole-batch AI-call error). `succeeded + failed = total`.
- **discards** — *spurious AI output* we threw away: an identifier that matched no
  remaining input row (`unmatched`) or a re-used identifier (`duplicate`). These
  are **not** input rows (the input rows lost to them are already counted as
  silent-drop *failures*), and the match is **mechanical, not semantic** — "first
  wins" is arbitrary, so we persist the raw discarded record and let a human judge.

They live in **one `solaris_batch_problems` table** with a `type` discriminator so
the report shows everything together, while the run keeps **separate counts** so
`failed` never conflates with `discarded`.

## Data model

### `solaris_batch_runs`

| column | type | notes |
|---|---|---|
| `id` | uuid, primary | the run's identity — generated app-side |
| `action_name` | string, index | |
| `user_id` | string, nullable, index | auth id stringified (portable across int/uuid PKs) |
| `page` | string, nullable, index | triggering Livewire/Filament page class; null for headless |
| `context` | string, nullable | optional app-set refinement |
| `status` | string | `BatchRunStatus`: pending / processing / completed / failed |
| `total` | unsigned int, nullable | input-row count |
| `succeeded` | unsigned int, default 0 | atomic increments; `succeeded + failed = total` |
| `failed` | unsigned int, default 0 | failed *input rows* |
| `discarded` | unsigned int, default 0 | discarded *outputs* (orthogonal to total) |
| `bus_batch_id` | string (uuid), nullable | populated by piece #3 |
| `started_at` / `finished_at` | timestamp, nullable | |
| `created_at` / `updated_at` | timestamps | |

No unique constraint across `(user_id, action_name, page)` — many runs allowed;
identity is `id`. The composite index on those three powers piece #5's recovery.

### `solaris_batch_problems`

| column | type | notes |
|---|---|---|
| `id` | bigIncrements | |
| `batch_run_id` | uuid, index | FK→runs |
| `type` | string, index | `'failure'` or `'discard'` |
| `identifier` | string, nullable | failure: the input row's identifier; discard: the echoed (wrong) identifier |
| `reason` | text | failure reason, or the discard's mechanical reason (carries `unmatched`/`duplicate`) |
| `description` | text, nullable | populated by piece #6 via `DescribesBatchFailure`; null here |
| `input` | json, nullable | failure: the source-row snapshot; discard: the thrown-away output record |
| `created_at` / `updated_at` | timestamps | |

Both tables register via Spatie `->hasMigrations(...)` and are publishable.
`user_id` is a portable nullable string (not a hard FK), documented.

## Models / enum / events

- `Models\SolarisBatchRun` — `HasUuids`, `status` cast to `BatchRunStatus`,
  `hasMany(SolarisBatchProblem)`, `markCompleted()`, configurable table name.
- `Models\SolarisBatchProblem` — `belongsTo` the run, `input` cast `array`,
  configurable table name. Convenience scopes `failures()` / `discards()`
  (`where('type', …)`).
- `Enums\BatchRunStatus` — pending / processing / completed / failed.
- `Events\SolarisBatchStarted` — `{ string $runId, string $actionName, ?string $userId, ?string $page, ?int $total }`.
- `Events\SolarisBatchCompleted` — `{ string $runId, string $actionName, int $succeeded, int $failed, int $discarded, BatchRunStatus $status }`.

Events follow the `SolarisResponseReceived` pattern (`final`, `Dispatchable`,
readonly, ids+counts only — no row data).

## Sinks

- `Support\DatabaseBatchSink implements BatchSink` — constructed with a run id.
  `record(BatchOutcome)` per batch, all via **atomic `increment()`** (race-safe
  for piece #3's concurrent jobs):
  - increment `succeeded`;
  - increment `failed` by `count($outcome->failures)` and bulk-insert each
    `FailedRecord` as a `type: 'failure'` problem row;
  - increment `discarded` by `count($outcome->discarded)` and bulk-insert each
    `DiscardedOutput` as a `type: 'discard'` problem row (`identifier` = the
    echoed id from its `record`, `reason` = its `reason`, `input` = its `record`).
  Holds nothing in memory beyond the run id.
- `Support\CompositeBatchSink implements BatchSink` — fans `record()` to an ordered
  list of sinks; lets the runner combine the in-memory collector (drives the
  post-run callback/manifest-log) with the DB sink.

`InMemoryBatchSink` (spec 28) is unchanged and remains the sole sink untracked.

## `AiGenerateAction` changes

- `->tracked(bool|Closure $tracked = true): static` — opt in. Default from config
  `batch_tracking.enabled` (default **false**).
- `executeRecordsLoop` when tracking resolves true:
  1. create a `SolarisBatchRun` (`status: processing`, `action_name`, `user_id`
     from `auth()->id()`, `page` from `get_class($this->getLivewire())` when a host
     exists, `total` from the resolved row count, `started_at`);
  2. fire `SolarisBatchStarted`;
  3. process through `CompositeBatchSink([new InMemoryBatchSink, new DatabaseBatchSink($run->id)])`;
  4. the existing discarded-logging + `finishBatchRun(...)` run **unchanged**
     (driven by the in-memory member);
  5. `markCompleted()`; fire `SolarisBatchCompleted` with the collector's
     succeeded / `count(failures)` / `count(discarded)`.
  When tracking is false, behaviour is **identical to spec 28**.

## Spec-28 touch (honesty rename)

`DiscardedOutput.kind` value `'hallucinated'` → `'unmatched'` in
`src/Support/DiscardedOutput.php` (the `@param` union), `src/Support/BatchProcessor.php`
(the `new DiscardedOutput(...)` call **and** the reason message
`'… hallucinated or missing identifier …'` → `'… unmatched identifier (no input row) …'`),
and every test asserting the old value/text (`BatchProcessorTest`,
`BatchOutcomeTest`, and the `AiGenerateActionPartialFailureTest` anomaly-channel
test that matches the message). `'duplicate'` is unchanged.

## Config

```php
'batch_tracking' => [
    'enabled' => (bool) env('FILAMENT_SOLARIS_BATCH_TRACKING', false),
    'runs_table' => 'solaris_batch_runs',
    'problems_table' => 'solaris_batch_problems',
],
```

`FilamentSolarisConfig` gains `isBatchTrackingEnabled()` + `getBatchRunsTable()` +
`getBatchProblemsTable()`.

## Testing

- **Unit:** `CompositeBatchSink` fans out. `DatabaseBatchSink` increments
  `succeeded`/`failed`/`discarded` and inserts `type: failure` + `type: discard`
  problem rows with the right `identifier`/`reason`/`input`. `SolarisBatchRun` uuid
  + status cast + `markCompleted`; `SolarisBatchProblem` `failures()`/`discards()`
  scopes.
- **Feature (`AiGenerateActionTrackingTest`):**
  - a `->tracked()` run (with one AI-reported failure **and** one unmatched
    discard in the fake) creates a `SolarisBatchRun` with `status: completed`,
    correct `succeeded`/`failed`/`discarded`/`total`/`page`/`user_id`; persists one
    `type: failure` + one `type: discard` problem row; fires
    `SolarisBatchStarted` + `SolarisBatchCompleted` (`Event::fake`, asserting the
    `discarded` count on Completed).
  - the **untracked default** path creates no run row and fires no events.
  - `->onPartialFailure()` + the manifest log still fire on a tracked run.
- Migrations run in tests via `(include $file)->up()` in `beforeEach`. Full suite
  + PHPStan L5 + Pint clean; simplifier on the changed surface.

## File structure

**New:** `src/Enums/BatchRunStatus.php`,
`database/migrations/create_solaris_batch_runs_table.php`,
`database/migrations/create_solaris_batch_problems_table.php`,
`src/Models/SolarisBatchRun.php`, `src/Models/SolarisBatchProblem.php`,
`src/Support/DatabaseBatchSink.php`, `src/Support/CompositeBatchSink.php`,
`src/Events/SolarisBatchStarted.php`, `src/Events/SolarisBatchCompleted.php`, tests.
**Modified:** `src/Support/DiscardedOutput.php` + `src/Support/BatchProcessor.php`
(the `unmatched` rename), `src/Actions/AiGenerateAction.php`,
`src/FilamentSolarisServiceProvider.php`, `config/filament-solaris.php` +
`src/FilamentSolarisConfig.php`, the spec-28 tests touched by the rename,
`tests/Fixtures/GenerateFormComponent.php`, `CHANGELOG.md`,
`documentation/ai-generate-action.md`.
