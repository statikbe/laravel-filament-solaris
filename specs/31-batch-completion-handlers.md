# Spec 31 — Batch completion handlers

> Piece #4 of the **Queued Batch Execution** roadmap
> (`docs/superpowers/specs/2026-06-08-queued-batch-execution-design.md`).
> Builds on spec 29 (persistence + events) and spec 30 (queued runner). Closes the
> two loose ends spec 30 deferred to this piece: the queued completion notification
> and the job-level-failure run-status gap (`specs/missing-features.md` #15).
> **Date:** 2026-06-13.

## 1. Goal

A **single completion seam** for `AiGenerateAction`'s records-loop — one extension
point invoked when a run finishes, identical whether the run executed **inline** or
**queued**. It supersedes the two parallel inline mechanisms (`->onPartialFailure()`
closure + `sendBatchSummary()` notification) with a configurable, queue-capable
**`BatchCompletionHandler`** strategy. The framework default sends the summary
notification; this is also the interface the CSV failure report (piece #6) will
implement, so the two compose on one action.

Nothing here is released, so this is a clean refactor — `->onPartialFailure()` and
`sendBatchSummary()` are removed, not deprecated.

## 2. The seam

### 2.1 `BatchCompletionHandler` interface
```php
interface BatchCompletionHandler
{
    public function handle(BatchSummary $summary): void;
}
```

### 2.2 `BatchSummary` — path-agnostic, serializable DTO
A deliberate refinement of the umbrella's sketched `handle(BatchRun $run)`: a DTO,
not the Eloquent model, so the **same** handler fires on the untracked-inline path
(where no run row exists) and so handlers don't depend on Eloquent.

```php
final readonly class BatchSummary
{
    public function __construct(
        public string $actionName,
        public ?string $runId,        // null = untracked inline run (no persisted row)
        public int $succeeded,
        public int $failed,
        public int $discarded,
        public BatchRunStatus $status,
        public bool $queued,          // which path built it — handlers branch on this, not on env-sniffing
        public array $userInput = [],
    ) {}
}
```

It carries **counts, not the failure rows** — a handler that wants failure *detail*
(or to write the report) queries `solaris_batch_problems` by `runId`. This respects
the queued "never hold all failures in memory" principle; failure-detail handlers
therefore require a persisted run (always true for queued; `->trackBatchRuns()` for
inline). For large runs that is the only sane contract.

### 2.3 Resolution — handlers compose
`->onCompletion(string|array $handlers)` accepts one or many handler class-strings
(class strings serialize, so they cross to the queue). Resolution per run:

1. the action's `->onCompletion()` value, if set; else
2. config `filament-solaris.batch_tracking.completion_handlers`; else
3. the framework default: `[NotifyOnBatchCompletion::class]`.

The resolved list is run **in order** (each resolved from the container). Piece #6's
report slots in as another entry — `->onCompletion([NotifyOnBatchCompletion::class,
GenerateFailureReport::class])` keeps both. Piece #4 ships only the one default
handler but builds the list machinery now.

## 3. Default handler — `NotifyOnBatchCompletion`

Turns the summary into a Filament notification; message + severity from counts +
status:
- all succeeded → **success** (`notifications.batch_completed`)
- some rows failed → **warning** (`notifications.batch_partial_failure`)
- status `Failed` (cancelled / job-level infra failure) → **danger**
  (`notifications.batch_failed`, new key in en/nl/fr)

**Delivery channel branches on `$summary->queued`:**
- **inline** → flash to the active session (`Notification::make()->…->send()`), exactly
  as `sendBatchSummary` does today.
- **queued** → no session on the worker, so send a **Filament database notification**
  to the initiating user: resolve `runId`→`run.user_id`→the auth user model
  (`config('auth.providers.users.model')::find()`), then `->sendToDatabase($user)`.
  **Defensive:** if the user can't be resolved, isn't `Notifiable`, or the
  `notifications` table is absent, catch and **log the summary** (via the failure
  logging channel) so it is never silently lost.

A config flag (`batch_tracking.notify_on_completion`, default `true`) disables the
default notification without having to override the handler list.

## 4. Wiring both paths

Both paths build a `BatchSummary` and run the resolved handler list; a shared
`runCompletionHandlers(BatchSummary $summary)` helper resolves + iterates (each
handler wrapped so a throwing handler is `report()`-ed and does not abort the rest).

- **Inline** (`finishBatchRun`): build the summary from in-memory counts (`runId` =
  the tracked run's id or null; `queued: false`), then run handlers. `reportFailures()`
  (the failure-manifest log) stays — it is the always-on safety net, independent of
  handlers.
- **Queued** (`FinalizeRun`): `markCompleted($status)` → fire `SolarisBatchCompleted`
  (the event substrate) → build the summary from the run row (`queued: true`) → run
  handlers.

### 4.1 Carrying completion context to the queued `finally`
`FinalizeRun` needs the per-action handler list + `userInput` on the worker. Add a
nullable **`meta` json column** to the existing `solaris_batch_runs` migration
(appended in place — nothing is released) holding `{userInput, completionHandlers}`,
written at `startBatchRun`. `FinalizeRun` reads `run.meta`. (`meta` also serves
#5/#6, as the umbrella anticipated.)

### 4.2 Job-level-failure status (#15)
`FinalizeRun` gains a `hasFailures` arg, passed from the Bus batch in the `finally`
hook (`$batch->hasFailures()`). Status becomes:
`cancelled → Failed`; else `hasFailures → Failed`; else `Completed`. So a run whose
*jobs* died at the infra level no longer reports a clean `Completed`, and the summary
the handler sees reflects it.

## 5. Removals (absorbed by the handler)

- `->onPartialFailure(Closure)` + the `$onPartialFailure` property — its role ("run
  something when there are failures") is now `if ($summary->failed > 0)` inside a
  handler.
- `sendBatchSummary()` — its role is `NotifyOnBatchCompletion`.
- Their tests/fixtures migrate to the handler equivalents.

## 6. Config

Add to the `batch_tracking` block:
```php
'completion_handlers' => [\Statikbe\FilamentSolaris\Support\Batch\Handlers\NotifyOnBatchCompletion::class],
'notify_on_completion' => true,
```

New classes live under the existing `Support\Batch` namespace: the
`BatchCompletionHandler` interface + `BatchSummary` DTO in `src/Support/Batch/`,
and `NotifyOnBatchCompletion` in `src/Support/Batch/Handlers/`.

## 7. Out of scope (still deferred)

- Run pruning / cleanup of old `solaris_batch_*` rows.
- `FailedRecord` ?\Throwable exception capture (debugging-only).
- Live updates (#5) — `->liveUpdates()` consumes the same events.
- The report **writer** (#6) — this spec only defines the `BatchCompletionHandler`
  interface it implements + the composing resolution.

## 8. Testing

- **`BatchSummary`** — constructibility + that handlers receive the right
  counts/`status`/`queued` values (it is rebuilt in each path, not serialized into a
  job, so no round-trip test is needed).
- **Resolution** — `->onCompletion(Single::class)` and `->onCompletion([A::class,
  B::class])` resolve to the right ordered list; empty → config → framework default;
  a throwing handler is `report()`-ed and the next still runs.
- **`NotifyOnBatchCompletion`** — inline path flashes (assert via Filament's
  notification testing); queued path sends a database notification to the run's user;
  no-resolvable-user falls back to a logged summary; `notify_on_completion=false`
  sends nothing. Message/severity for each of success / partial / failed.
- **`FinalizeRun`** — `hasFailures: true` → status `Failed` + danger summary; reads
  handler list + userInput from `run.meta`; event fires before handlers.
- **Inline parity** — a tracked inline run and a queued run with the same outcome
  produce the same handler invocation (counts/status), differing only in `queued`.
- **End-to-end** — `->queued()` import under sync queue lands a database notification
  for the initiating user on completion.
