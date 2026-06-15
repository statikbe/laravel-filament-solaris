# Spec 32 — Batch table pruning command

> Backlog item #16 of `specs/missing-features.md` (the umbrella's deferred "run
> cleanup / pruning"). Builds on spec 29 (persistence) and spec 31 (completion
> handlers). **Date:** 2026-06-15.

## 1. Goal

A schedulable artisan command, `solaris:prune-batches`, that deletes old
`solaris_batch_runs` (and their `solaris_batch_problems`) to keep the tracking
tables tidy once `->trackBatchRuns()` / `->queued()` are in use. Plus a schema fix:
give `solaris_batch_problems.batch_run_id` a **cascading foreign key** so a problem
can never outlive its run (and pruning a run cascades its problems automatically).

Nothing is released, so the FK is added by **amending the existing piece-#2
problems migration**, not a new one.

## 2. Foreign key (schema fix)

`database/migrations/create_solaris_batch_problems_table.php`: replace the bare
`$table->uuid('batch_run_id')->index();` with a cascading FK to the runs table
(respecting the configurable table name):

```php
$table->foreignUuid('batch_run_id')
    ->constrained(config('filament-solaris.batch_tracking.runs_table', 'solaris_batch_runs'))
    ->cascadeOnDelete();
```

(`foreignUuid(...)->constrained()` already creates the index, so drop the explicit
`->index()`.)

**Why it's safe here:**
- No code or test inserts a problem with a fabricated `batch_run_id` — the
  `DatabaseBatchSink` always writes the `runId` of a run created at `startBatchRun`.
- Real `php artisan migrate` runs runs-before-problems (Spatie timestamps in
  registration order: runs registered first).
- The test harness boots migrations via an alphabetical `glob()` (`problems` sorts
  before `runs`). The test DB is in-memory SQLite, which **defers FK-target
  existence to runtime**, so creating the problems table with the FK before the
  runs table is fine. **Verify during implementation**; if it does break, extract a
  shared `bootBatchMigrations()` test helper (in `tests/Pest.php`) that migrates the
  runs migration before the problems migration, and point the batch test files'
  `beforeEach` at it.

## 3. Command — `solaris:prune-batches`

`src/Commands/PruneBatchRunsCommand.php`, registered in
`FilamentSolarisServiceProvider::configurePackage()` via `->hasCommand(...)`.

**Signature:**
```
solaris:prune-batches
    {--days= : Delete runs finished more than this many days ago (overrides config).}
    {--force : Run without the production confirmation prompt.}
```

Uses Laravel's `Illuminate\Console\ConfirmableTrait` so a scheduled/production run
needs `--force` (matches Spatie's `activitylog:clean`).

**Behaviour:**
1. Resolve retention: `$days = $this->option('days') ?? config('filament-solaris.batch_tracking.prune_after_days')`.
   - If null / not a positive integer → print an error and return failure
     (`"No retention window: pass --days or set batch_tracking.prune_after_days."`).
     **Opt-in by default — never deletes without an explicit window.**
2. `confirmToProceed()` — abort if declined (unless `--force` / non-production).
3. Compute `$cutoff = now()->subDays($days)`.
4. Delete **terminal** runs only — `status IN (Completed, Failed)` AND
   `finished_at < $cutoff` — **in chunks** (e.g. 500 ids at a time): select a chunk
   of prunable run ids, `SolarisBatchRun::whereKey($ids)->delete()`; the FK cascades
   each chunk's problems. Chunking keeps each cascade transaction small. In-flight
   `Processing`/`Pending` runs (and any with a null `finished_at`) are never touched.
5. Output (Spatie-style): `"Pruning batch runs finished before {cutoff}…"` →
   `"Pruned {count} batch run(s)."` → return success.

Respects the configurable `runs_table` via the `SolarisBatchRun` model.

## 4. Config

Add to the `batch_tracking` block (documented):
```php
'prune_after_days' => null, // solaris:prune-batches retention; null = must pass --days
```

## 5. Scheduling

**Documented, not auto-registered** (the package shouldn't schedule deletions
behind the app's back). In `documentation/`:
```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('solaris:prune-batches', ['--force'])->daily();
```
(Note `--force` is required for unattended runs.)

## 6. Testing

- **FK cascade:** deleting a `SolarisBatchRun` removes its `solaris_batch_problems`
  rows (model `delete()` and bulk `whereKey()->delete()`).
- **Command — happy path:** seed terminal runs older + newer than the window (+ a
  `Processing` run inside the window); run with `--days` + `--force`; assert only the
  old terminal runs and their problems are gone, recent + in-flight survive.
- **Command — retention guard:** no `--days` and null config → fails with the
  guidance message, deletes nothing.
- **Command — config default:** `prune_after_days` set, no `--days` → uses config.
- **Command — confirmation:** without `--force` in a "production" environment the
  command aborts (use Laravel's command testing for the confirmation).
- **Chunking:** more runs than the chunk size are all pruned (loop covers >1 chunk).

## 7. Out of scope

- Auto-registering the schedule.
- Pruning by action_name (`--action=`) — YAGNI for now.
- Pruning orphaned `job_batches` rows (Laravel's own table; `queue:prune-batches`
  covers it).
