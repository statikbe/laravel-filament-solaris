# 36 — AiGenerator service (headless AI orchestration) — design

> **Status:** Design doc (umbrella for the "AiGenerator extraction" arc). Awaiting
> user review before an implementation plan. Decomposes into per-piece specs
> (37+) below.
> **Date:** 2026-06-23.
> **Supersedes** the direction sketched in `35-aigenerateaction-parity-and-plausibility-review.md`
> (which proposed a return-only single-call service). Decision since: the service
> owns the **full** engine (single + batch, sync + queued), to avoid a split-brain.

---

## Goal

Extract the AI orchestration engine out of the Filament actions into a
standalone, headless service — **`AiGenerator`** — that runs structured AI
generation independent of any Filament action, Livewire component, or form.
Callable from jobs, listeners, commands, and chainable across calls (different
models/prompts/outputs). The Filament actions become thin adapters that collect
input and delegate.

Two motivations:
1. **Reuse beyond Filament** — e.g. knxcou's plausibility check runs from a
   `SubmissionSubmitted` listener; multi-model chains run from plain PHP.
2. **One engine, not three** — today single-call, batch, and queued
   orchestration are entangled inside `AiGenerateAction` + `HasQueuedExecution` +
   `HasPromptPipeline`. A partial extraction (single-call only) would leave a
   split-brain — the exact `HasPromptPipeline` duplication debt we already carry.
   **Now (pre-release) is the time to consolidate.**

---

## Architecture — two layers

**Layer 1 — `AiGenerator` (new, headless).** Owns config resolution, execution
(single vs records-loop, inline vs queued), persistence (create/update/upsert),
tracking (`SolarisBatchRun` + events), and completion handlers. Filament-free at
the call site. Fluent builder. Throws on failure (no UI notifications — that is a
UI concern).

**Layer 2 — Filament actions (refactored adapters).**
- `AiGenerateAction` → thin adapter: button, `userInput` modal, evaluation of
  Filament closures, error/started notifications, live-update polling. Delegates
  the **entire** engine to `AiGenerator`.
- `AiFormAction` → keeps source/target fields, factory write-back, preview,
  conversational, `sanitize`. Uses `AiGenerator` for the **AI call only**
  (single `runSync`), then applies its own form write-back. It does *not* inherit
  the records/batch/queue machinery.

Why the asymmetry: form write-back (factories, preview, conversational) is
inherently a live-form concern and stays Filament-side; the *records → persist*
domain is `AiGenerateAction`'s and moves wholesale into the service.

---

## I/O contract

### Fluent config (the builder)
- **Prompt:** `prompt(string|View|Closure|Preset)`
- **Schema:** `outputSchema(Closure)` **or** `forModel(class)` (+ `only`/`except`/
  `columnHint`/`columnEnum`/`identifyBy`)
- **Provider:** `provider(...)`, `model(...)`, `timeout(...)`
- **Generation options:** `temperature`/`maxTokens`/`maxSteps`/`topP`
- **Attachments:** `attachments(...)`
- **Source (batch):** `sourceRecords(...)` / `count(...)` (seed-from-scratch)
- **Write-back terminal:** `handleUsing` | `createRecords` | `updateRecords`
  (+ later `upsertRecords(uniqueBy:)` / `recordAttributes()` — see piece 5)
- **Batch tuning:** `batchSize`, `promptContextColumns`
- **Tracking:** `trackBatchRuns`
- **Completion:** `onCompletion(handlers)`, `withFailureReport`
- **Sanitisation:** `sanitize` / `sanitizeField` (ported, piece 4)
- **Input:** the resolved `$userInput` array (the modal is action-side; the
  action passes the resolved array in)

Closures inside `AiGenerator` are evaluated with plain `value()` (receiving
`$userInput` / `$rows`), **not** Filament's `evaluate()`. The action evaluates
its Filament-DI closures first and hands plain values/resolvers to the service.

### Terminals (named on the inline/queued axis)
```php
->runInline(): GenerationResult | BatchSummary   // execute in-process, return the result
->runQueued(): SolarisBatchRun                   // dispatch to the queue, return a handle
```
- Names match the package's own "inline vs queued path" vocabulary and the
  existing `->queued()` flag.
- Single-vs-batch is **inferred from config** (was `sourceRecords()` set?), not
  encoded in the method name.
- The split is principled, not cosmetic: a queued run *cannot* return a result —
  only a handle; the outcome arrives later via events + completion handlers.
- **Sub-fork (open):** `runInline` returning a `GenerationResult|BatchSummary`
  union vs splitting into a single-call terminal (→ result) and a batch terminal
  (→ summary). Working default: union + infer (smaller surface).

### Outputs
- `GenerationResult { data: array, usage: ?Usage, raw: AgentResponse, model: ?string }` — single call (new DTO).
- `BatchSummary { succeeded, failed, discarded, status, runId, queued, … }` — batch (exists).
- `runQueued()` → `SolarisBatchRun` (the persisted handle).

---

## Pipeline: click → notification

```
① click
② userInput modal ─────────────────► $userInput (array)            [ACTION]
③ evaluate Filament closures → plain values/resolvers              [ACTION]
④ build AiGenerator config; call runInline() or runQueued()        [ACTION]
─────────────────────────── seam ───────────────────────────
⑤ resolve config → execute (single|batch, inline|queued)          [AiGenerator]
⑥ persist (create/update/upsert) + track (SolarisBatchRun, events) [AiGenerator]
⑦ completion handlers                                              [AiGenerator]
⑧ notification (inline flash | queued DB notification)            [completion handler]
```

**Notification ownership:**
- Completion notification (⑧) → completion handlers, inside `AiGenerator`
  (already headless: flash inline / DB notification on the queue).
- The action owns only: the optional "started" notice for `runQueued()` + live-
  update polling, and converting a thrown `AiException` (inline path) into the
  user-facing **error** notification.

**Error handling:** `AiGenerator` fires the `SolarisResponseFailed` domain event
and **throws** `AiException` on a single-call failure; the action catches it and
notifies (preserving today's UX). The batch path captures per-record/per-chunk
failures into the summary (as now) and does not throw for those.

### Inline vs queued reconciliation in the action

The action is the **only place that reconciles** the two — a headless caller
simply calls the terminal it wants. The action maps its existing `->queued()`
flag onto the terminal:

```php
// AiGenerateAction::execute(), after building $generator
if ($this->isQueued()) {
    $run = $generator->runQueued();          // SolarisBatchRun (work dispatched)
    $this->sendStartedNotification($run);
    return;                                   // liveBatchUpdates() polling + the
                                              // completion DB notification take over
}

$result = $generator->runInline();           // GenerationResult | BatchSummary (in hand)
```

The branch itself is trivial; the **post-terminal UX differs**, and that
difference is what justifies keeping it in the action:

| | inline (`runInline`) | queued (`runQueued`) |
|---|---|---|
| returns | result, in hand | run handle, work dispatched |
| completion notification | fired in-request by completion handlers (flash) | fired later on the worker (DB notification) |
| errors | `AiException` thrown → action catches → error notification | dispatch-time validation only (e.g. attachment serialisation); per-call failures captured in the run + failure report |
| follow-up | none (single-call: handler side effects + action's error path as today) | "started" notice + `liveBatchUpdates()` polling |

Guards (e.g. `->queued()` requires `forModel` + create/update — closures can't
serialise) are validated **before** the branch; an incompatible combo errors
rather than silently running inline.

---

## Chaining calls

Chaining is a **free consequence** of `runInline()` returning a
`GenerationResult` whose `->data` is the parsed structured output. No dedicated
API is needed for v1 — capture a result and feed it into the next builder,
varying model, prompt, or schema per step:

```php
$draft = AiGenerator::make()
    ->prompt('Draft a product blurb for: '.$name)
    ->provider('openai', 'gpt-4o-mini')
    ->outputSchema(fn ($s) => ['blurb' => $s->string()])
    ->runInline();

$polished = AiGenerator::make()
    ->prompt('Tighten this to two sentences: '.$draft->data['blurb'])
    ->provider('anthropic', 'claude-sonnet-4-5')        // different model
    ->outputSchema(fn ($s) => ['blurb' => $s->string(), 'score' => $s->integer()])
    ->runInline();
```

Because each step is an independent `AiGenerator`, "different models / prompts /
outputs per step" falls out for free. Error handling is ordinary PHP — a thrown
`AiException` stops the chain; wrap in `try/catch` where needed.

**Out of scope for v1 (add only if the need proves real):**
- **A dedicated chain abstraction** (`AiGenerator::chain()->step(...)->step(...)`)
  that threads each step's output into the next with shared error handling /
  observability. YAGNI until multi-step flows proliferate and the plain-PHP
  boilerplate hurts.
- **Queued chaining.** Chaining needs each step's *result* to build the next, so
  it is inherently inline. Chaining *queued* steps means triggering the next from
  the previous one's completion (a `Bus::chain` of jobs, or a completion-handler
  continuation) — a separate, event-driven mechanism to design later if needed.

---

## Relocation plan (behaviour-preserving)

**Moves into `AiGenerator`:** config resolution; the execute branch (single vs
records-loop); `BatchProcessor` invocation + reconciliation + persistence; queued
dispatch (`Bus::batch`, `ProcessChunkJob`, `FinalizeRun`) currently in
`HasQueuedExecution`; tracking, events, completion handlers, failure report; the
`executeAiCall` wrapper (minus the UI notification).

**Stays on `AiGenerateAction` (adapter):** its fluent setters (which accept
Filament-DI closures and, at execute time, resolve to plain values that
configure an internal `AiGenerator`); `userInput` modal; notifications (started +
error); `liveBatchUpdates()` button behaviour; user-facing validation messages.

The action **keeps its public fluent API** (`->forModel()`, `->createRecords()`,
`->queued()`, …) for backward compatibility and Filament-closure support; it
translates that state into an `AiGenerator` at execute time. The existing 634
tests guard the behaviour through the move.

---

## Decomposition into pieces (each TDD, tests green, merged sequentially)

These are **refactor steps**, not shipping boundaries — the service reaches full
capability before anything is released, so there is no "single-call-only" public
release. (Nothing in this package is released yet.)

1. **AiGenerator core + single call.** Extract `executeAiCall` + agent build +
   structured parse → service. `GenerationResult`. Events fire from the service;
   action catches `AiException` → notification. Route `AiFormAction` +
   `AiGenerateAction` single-call through it. (Foundational, lowest risk.)
2. **Records-loop + write-back into the service** (`runInline` batch). Move
   `BatchProcessor` invocation, create/update, sink, tracking, completion
   handlers. `AiGenerateAction` batch path delegates.
3. **Queued dispatch into the service** (`runQueued`). Move `HasQueuedExecution`.
   `AiGenerateAction->queued()` delegates.
4. **Parity ports:** `sanitize`/`sanitizeField`, preset prompt source, `tools()`
   on the service (from Part A of spec 35).
5. **(later) New write-back terminals:** `upsertRecords(uniqueBy:)`,
   `recordAttributes()`, `identifyBy()` — enables knxcou's declarative path.
   Deferred; not required for knxcou v1 (which uses `runInline` single +
   `updateOrCreate` in the listener).

---

## Decisions locked
- Service name: **`AiGenerator`** (pairs with `AiGenerateAction`). No `Solaris`
  facade (too close to `FilamentSolaris`).
- Do the **full** cleanup now — service owns single + batch + sync + queued.
- Service **throws**; action **notifies**. Completion handlers (in the service)
  own the completion notification.
- `AiFormAction` uses the service for the call only; keeps form write-back.
- Single-vs-batch inferred from config.
- Terminal naming: **`runInline`** / **`runQueued`** (matches package vocabulary).

## Open / to confirm
- `runInline` union return vs split single/batch terminals.
- Entry style for headless use: `AiGenerator::make()->…` (static factory) vs
  container resolution / a facade. Working default: `AiGenerator::make()`.
- Final decomposition order (above is the proposal).
