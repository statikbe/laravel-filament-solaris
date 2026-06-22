# Spec 34 — Live updates (`->liveBatchUpdates()`)

> Piece #5 of the **Queued Batch Execution** roadmap
> (`docs/superpowers/specs/2026-06-08-queued-batch-execution-design.md`).
> Builds on spec 29 (persistence + events), spec 31 (completion handlers).
> **Date:** 2026-06-22.

## 1. Goal

While a queued batch run is in flight, reflect its progress **on the action button
itself**: disable the button (prevents re-running before it finishes), show live
progress in a tooltip, and re-enable it when the run completes — driven by polling
the run row, with no required infra. Plus make the batch events **broadcast-ready**
(opt-in, auto-detected) so apps with Echo/Reverb can drive snappier UIs without the
package requiring broadcasting.

## 2. Why the button (not a separate component)

A `->queued()` action returns at dispatch, so Filament's native button spinner (the
synchronous in-request indicator) stops in ~50 ms — it can't track the async job. A
separate progress component has no natural placement next to a header/row action.
Making the **button** the indicator sidesteps placement and doubles as re-run
protection. The button can self-inject `wire:poll` (an attribute) but **cannot**
self-subscribe to Echo (listeners are component-level), so polling is its transport;
broadcasting is an app/host-level upgrade (§5).

## 3. `->liveBatchUpdates()` — button state

`AiGenerateAction::liveBatchUpdates(bool|Closure $enabled = true): static`. Pairs with
`->queued()` (it reads `SolarisBatchRun` rows, which queued always creates; inline
runs are synchronous so the button is naturally busy). When enabled, the action's
render-time closures reflect the user's in-flight run of this action:

- **Active run resolution** — `activeLiveRun(): ?SolarisBatchRun` = the latest run
  with `action_name = $this->getName()`, `user_id = (string) auth()->id()`, `status =
  Processing` (indexed lookup; `user_id`/`action_name`/`status` are already indexed).
- **`->disabled(fn () => liveBatchUpdates enabled && activeLiveRun() !== null)`** — busy +
  re-run guard.
- **`->tooltip(fn () => …)`** — when active: `filament_solaris_trans('actions.batch_progress',
  ['done' => succeeded+failed, 'total' => total ?? '?', 'failed' => failed])` →
  e.g. "Processing 40 / 100 — 2 failed". Null when idle.
- **`->icon(fn () => active ? <spin icon> : <original>)`** — swap to a spinning icon
  while active (icon swap keeps button width stable; layout-safe, unlike a label
  suffix). The spin is the `animate-spin` class on the icon (apply via Filament's
  icon API; if not cleanly supported, fall back to a static "processing" icon — a
  nice-to-have, not load-bearing).
- **`->extraAttributes(fn () => active ? ['wire:poll.3s' => ''] : [])`** — the button
  **self-polls only while a run is active**: the dispatch re-render turns polling on,
  and the first poll after the run goes terminal turns it off (no idle polling, no
  host changes). Poll interval from `batch_tracking.live_updates.poll_interval` (default `3s`).

Register these closures **in `liveBatchUpdates()`** (not `setUp()`), so they're only added
when opted in and don't fight a user-set `->disabled()`. Each closure also guards on
the resolved `$enabled` flag so a `Closure`/`false` value short-circuits.

**Numeric label suffix is intentionally NOT used** — it changes button width as digits
grow (ugly in table columns). Progress lives in the tooltip.

> **Implementation risk to verify first:** that `wire:poll.Ns` placed via an action's
> `extraAttributes` actually polls the host Livewire component (re-evaluating the
> button's closures). ~90% confident. If it doesn't pass through, fall back to
> documenting a `->poll()` on the host table/page as the trigger.

## 4. Resolution / scoping

- `activeLiveRun()` scopes by `action_name + user_id + status=Processing`. A header/page
  action (the common queued case) maps 1:1. **Limitation (documented, future work):** a
  per-record **row** action shares one `action_name`, so any in-flight run of it disables
  the button across rows for that user. Acceptable for v1.
- Requires a resolvable `auth()->id()` (the run's `user_id`). If null (no auth), the
  active-run query is skipped → button never enters the live state. Fine.

## 5. Broadcast-ready events (opt-in, auto-detected)

Make `SolarisBatchProgressed` and `SolarisBatchCompleted` implement `ShouldBroadcast`:

- **`broadcastOn(): Channel`** → a **public** channel `new Channel("solaris.batch.{$this->runId}")`.
  The events carry **counts only, no row data** (the package's PII rule), and the runId
  is an unguessable uuid — so a public channel needs no auth route. Keeps "offer
  broadcasting" cheap.
- **`broadcastWhen(): bool`** → auto-detect: `config('filament-solaris.batch_tracking.live_updates.broadcast')`
  resolved as: `true`/`false` force it; **`null` (default) → broadcast iff
  `config('broadcasting.default') !== 'null'`** (the app actually has a driver). So zero
  broadcast attempts when no driver is configured — infra-free by default.
- `broadcastAs()` → stable names (`solaris.batch.progressed` / `.completed`) so JS
  listeners are version-stable. `broadcastWith()` → the existing scalar payload.

This is **offered, not consumed by v1's button** (the button can't self-subscribe to
Echo). Apps with broadcasting can listen on the channel from their own host component
to drive a snappier UI / skip polling — documented as the upgrade path. Adding
`ShouldBroadcast` must not break the existing event tests (they assert dispatch, not
broadcast; with `broadcast` unset and the test env's `broadcasting.default = null`,
`broadcastWhen()` is false → no broadcast attempt).

## 6. Config — restructure `batch_tracking` into nested groups

`batch_tracking` has grown to ~10 flat keys; nothing is released, so regroup it now
(no deprecation needed). Final shape:

```php
'batch_tracking' => [
    'enabled' => (bool) env('FILAMENT_SOLARIS_BATCH_TRACKING', false),
    'database' => [
        'tables' => [
            'runs' => 'solaris_batch_runs',       // was batch_tracking.runs_table
            'problems' => 'solaris_batch_problems', // was batch_tracking.problems_table
        ],
        'pruning' => [
            'after_days' => null,  // was batch_tracking.prune_after_days
            'chunk' => 500,        // was batch_tracking.prune_chunk
        ],
    ],
    'completion' => [
        'handlers' => [NotifyOnBatchCompletion::class], // was batch_tracking.completion_handlers
        'notify' => true,                               // was batch_tracking.notify_on_completion
        'failure_report' => true,                       // was batch_tracking.attach_failure_report
    ],
    'live_updates' => [
        'poll_interval' => '3s',
        'broadcast' => null, // null = auto (on when broadcasting.default !== 'null'); or true/false
    ],
],
```

**This renames existing keys** — every `config('filament-solaris.batch_tracking.*')`
read must move to the new path. Affected (all merged, all unreleased): the two
migrations + `SolarisBatchRun`/`SolarisBatchProblem` `getTable()` (→ `database.tables.*`),
`NotifyOnBatchCompletion` (→ `completion.notify`/`completion.failure_report`),
`AiGenerateAction::resolveCompletionHandlers`/`resolveAttachFailureReport` +
`CompletionHandlerRunner::resolve` + `FinalizeRun` (→ `completion.handlers`),
`PruneBatchRunsCommand` (→ `database.pruning.*`), and the tests that `config()->set(...)`
those paths. The `run.meta['attach_failure_report']` *stash* key is unaffected (it's a
meta dict key, not a config path). Done as its own plan task before the feature work.

## 7. Out of scope

- An embeddable progress-bar component / a dedicated runs page.
- Host-side Echo wiring / a broadcast-driven poll-skip (documented as the upgrade
  path, not built).
- Per-record row-action scoping.
- Live-updating the completion notification (the bell entry is static; piece #6's
  download links already live there).

## 8. Testing

- **`liveBatchUpdates()` resolution:** with no active run → button not disabled, no tooltip,
  no `wire:poll` attr. With an in-flight run (seed a `Processing` `SolarisBatchRun` for
  the action+user) → disabled true, tooltip contains the progress string, extraAttributes
  carries `wire:poll.3s`. After the run is `Completed` → back to enabled/no-poll.
  (Drive via the action's evaluated closures, or a Livewire render of a fixture host.)
- **Scoping:** an in-flight run for a *different* action or *different* user does not
  disable this action's button.
- **Disabled-when-off:** without `->liveBatchUpdates()`, none of the live closures apply.
- **Broadcast gating:** `SolarisBatchProgressed`/`Completed` do NOT broadcast when
  `broadcast` is null + `broadcasting.default = 'null'` (assert via `Event::fake` /
  `Broadcast::fake` that no broadcast goes out); they broadcast on
  `solaris.batch.{runId}` when `broadcast = true`. `broadcastWith` carries the counts.
- Existing event + completion tests stay green (no broadcast attempted in the test env).
