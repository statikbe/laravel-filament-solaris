# 41 — Handler terminal, agent reuse & per-row attachments

> **Status:** DECISIONS TAKEN (2026-09-24) — all §6 open questions are resolved; see §6.
> Still an assessment, not an implementation spec: it records what a second real consumer
> (Pacioli) demands of the generation engine and the design choices we made in response.
> Next step is spawning numbered implementation specs in the §7 order.
> **Date:** 2026-09-10 (decisions 2026-09-24). **Author context:** Sten + Claude.
> **Relates to:** `35-…-plausibility-review.md` (validation case #1, knxcou),
> `36-ai-generator-service.md` (the arc this extends), `39-conversational-preview-exploration.md`
> (staging + chat — see §5).
> **Not in scope:** integrating Solaris into Pacioli. Pacioli is being used as a design
> probe; no integration work is proposed here.

---

## 1. Diagnosis — the records loop only exists in write-back form

Pacioli's natural shape is: **one AI call per uploaded invoice, each with its own PDF
attached, the result handed to the app's own service, on a queue.** That shape is blocked
in three places today, and they share one root cause.

| Constraint | Where |
|---|---|
| `->sourceRecords()` requires `createRecords()`/`updateRecords()`; the handler never runs per row | `src/Actions/AiGenerateAction.php:813` |
| `->queued()` requires `createRecords()`/`updateRecords()`; handler-mode cannot be queued | `src/Actions/AiGenerateAction.php:838` |
| `$attachments` is a flat array resolved once per run and sent to every call | `src/Generation/AiGenerator.php:72`, used at `:794` |

Plus one omission from the arc: **`AiGenerator` has no handler terminal at all.**
`handleUsing()` exists only on `AiGenerateAction`; the service was given
`createRecords()`/`updateRecords()` and nothing else. Headless callers get a
`GenerationResult` from a single `runInline()` and are on their own for anything
iterative.

The framing that matters: these are not three features. **The records loop was built for
write-back and only ever grew a write-back terminal.** Making the handler a real terminal
on the loop is the single change; per-row execution and queued handler runs fall out of
it rather than being bolted on beside it.

---

## 2. Pacioli as validation case #2

### What it is

A personal Exact Online front-end (Laravel 12 / Filament v5 / PostgreSQL) that replaces
Exact's paid PDF-scan-to-booking service. Today the "agent" is Claude Code driving an MCP
server (`app/Mcp/Tools/`): read the staged PDF, search relations, list GL accounts, pull
supplier history, then `create_invoice_proposal`. A Filament review page is the approval
gate before anything reaches Exact.

### The architectural fact that matters to us

Pacioli's §7 **ladder** decides every accounting field: `FieldResolver` walks
`relation_default > history > llm` and **discards** the model's value whenever a higher
rung answers. The discarded suggestion — with its confidence and reasoning — is recorded
*beside* the decision in `proposal_field_decisions`, for display only. `BookingAuditor`
then produces findings; a blocking one parks the proposal as `needs_correction`.

**Nothing the model returns is ever written directly.** Structured output must land in
`ProposalService`, which decides.

### How that differs from knxcou (case #1)

| | **knxcou** (spec 35) | **Pacioli** (this spec) |
|---|---|---|
| Complaint | Declarative write-back isn't expressive enough | Doesn't want write-back at all |
| Asked for | `upsertRecords` / `recordAttributes` / `identifyBy` | A real handler terminal, per-row, queueable |
| Unit of work | A synthetic per-field row | One uploaded document |
| Attachments | Optional (a CSV, or inline stats) | **The entire subject of the call** |
| `tools()` | Not needed | Load-bearing — four MCP tools already exist |
| Status | Never built | Built and in daily use, minus Solaris |

Two consequences worth stating plainly:

1. **`handleUsing()` should stay a first-class peer of `forModel()`**, not the escape
   hatch. Half of our real consumers want it as the primary terminal.
2. **It is a mild argument against rushing knxcou's `upsertRecords`.** The one consumer
   that wanted richer write-back was never built; the one that exists doesn't want
   write-back. Sequence accordingly.

### The genuine win

Pacioli is the first end-to-end validation of **`tools()` on real work**. `search_relations`,
`list_gl_accounts`, `supplier_history` and `list_vat_codes` already exist as MCP tools;
wrapping them as `laravel/ai` tools is exactly the "enrichment via tools" case spec 39
had only a hypothetical for — and here the tool output feeds a *deterministic* decision
afterwards rather than being trusted on its own, which is a better pattern than the one
we invented.

---

## 3. Agreed capabilities, and the choices still open

These four are **wanted** (agreed 2026-09-10). How each is shaped is **not** settled —
that is what this section is for.

### 3.1 The handler as a real terminal

*Agreed:* `handleUsing()` becomes a terminal on the records loop, on the service as well
as the action, and works queued.

**Decided — a per-row handler receives a dedicated per-row DTO.** Today handler-mode gets
a raw `array` (custom schema) or a `BatchResponse` (forModel). Neither fits per-row.
Reusing `BatchResponse` would be a category error — that is a batch shape. New shape,
provisionally `RowResult`: `output` (raw parsed array, see §4.5), `source` (the row or
Model it came from), `attachments` (`File[]` sent with this row), `index`, plus the
idempotency key (below). Delivered via Filament-style DI — `fn (RowResult $result)` or
named args `$output` / `$source` / `$attachments`. The DTO is the serialisation and
test-assertion unit; DI keeps the handler terse. **No custom-DTO hook for now**
(e.g. `->rowResultClass()`): Solaris constructs the DTO, so an app wanting richer data
wraps/maps it inside its handler. Add the hook only on demonstrated need.

**Decided — fan-out is a distinct terminal, not `batchSize(1)`.** "N rows in one prompt,
reconcile by identifier" and "one call per row" are different machines: different failure
semantics, different retry semantics, and fan-out needs none of the `## Records`
identifier-echo contract. `batchSize(1)` would hide that and silently change retry
behaviour for someone tuning cost. The extra API concept is the accepted cost; name TBD in
the implementation spec (`->eachRow()` / `->perRecord()`).

**Decided — retries: caller-settable on handler terminals, plus an idempotency key.**
Write terminals stay locked at `tries = 1` (`createRecords` is not idempotent). Handler /
fan-out terminals get `->tries(n)` (+ backoff) — transient LLM failures are common enough
that 1 is harsh when the handler is retry-safe. Solaris passes each handler a stable
per-row key (run id + row index) so "the app owns idempotency" is cheap to honour
(Pacioli's content-addressed `StagedDocument` with `used_at` already fits).

### 3.2 Reusable agent

*Agreed:* one configured agent should serve several call sites — in Pacioli's case a
single-upload action, a dropzone, an intake listener and a re-propose action.

**The hazard today:** `AiGenerator` is a *mutable* fluent builder. Sharing a configured
instance across call sites is a footgun, not a feature — the second caller mutates the
first's config.

**Decided — full definition/run split, now (option A).** An immutable `AgentDefinition`
(prompt, schema, provider, model, timeout, options, tools, `forModel` column hints/enums,
sanitizers, `promptContextColumns`) *produces* runs; a transient run object carries the
per-run data and context (`sourceRecords`, `recordCount`, `userInput`, livewire, user,
terminal, completion handlers, `tracked`; run-level attachments sit on the run). "Same
agent, several terminals" becomes structural rather than conventional. It is also the
anchor for the chat work (§3.4) and a single testing seam.

Rejected alternatives: *clone-on-set on `AiGenerator`* (one class doing both jobs; a
forgotten `clone` reintroduces the footgun) and *hybrid `->define()` snapshot* (the
definition/run property split is the same design work either way; the hybrid keeps the
shareable-mutable hazard and two ways to do one thing, and defers a larger break).

Consequences accepted:
- **Breaking change in 0.5.** `AiGenerator` shipped in 0.4.0; its fluent flow changes
  (it becomes the run, or is renamed). Pre-1.0, few consumers — the break is cheapest now.
  Needs an upgrade note.
- A fluent definition must itself be clone-on-set internally. That is fine: the
  fragility of clone-on-set is a large mixed-state class, not a small pure-config one.
- The `AiGenerateAction` translator and the four `AiGenerator` test files are rewritten;
  the queued path's `BatchRunConfig` likely becomes (or derives from) a definition
  snapshot — to be confirmed in the implementation spec.

**Decided — registration: a value object; app-level binding optional.** A definition is a
value object constructed where needed. An app that wants one shared instance binds it in
a service provider itself. Solaris ships no registry.

### 3.3 Per-row attachments

*Agreed:* each row carries its own attachment(s). One job per invoice is acceptable —
per-invoice LLM processing is expensive enough that per-row jobs are the right grain
anyway.

This gets substantially simpler under fan-out: the attachment travels in the **chunk
descriptor**, not in run-level config. Note the existing queue-serialisation guard
(`AiGenerator.php:531`, `:538`) already handles disk-backed files correctly — Pacioli's
`StagedDocument` stores via `Storage::disk('local')` and serialises fine; the guard only
rejects raw filesystem paths.

**Decided — resolution timing: in-request at dispatch, not lazy.** Matches current
behaviour for run-level attachments and is the safer choice. Lazy worker-side resolution
is not built.

### 3.4 Chat — postponed, but keep the seam

*Agreed:* deferred. Not deferred: making sure we do not preclude it.

What to preserve: **guidance-as-a-string re-run** must stay possible without conversation
persistence — the owner types a correction, the same definition re-runs with that string
appended, a fresh result comes back. That is the cheap 80% of Pacioli's "chat beside the
proposal", it is forward-compatible, and it is the same call spec 39 already made for
batch refinement (re-`stage()` with accumulated `guidance()`, no LLM continuation).

The full version remains blocked as documented: `missing-features.md` #11 (the
`solaris_conversations` morph table) plus the upstream gap where `laravel/ai` does not
rehydrate `Message::attachments` into `File[]`. Pacioli's conversation is
attachment-heavy by definition — the PDF *is* the subject — so it hits that wall directly.

---

## 4. Additions

Ranked by conviction. The first two are build items; the last three are constraints on
how the work is shaped.

### 4.1 Batch outcomes carry counts, not identities — high conviction

`BatchSummary` is counts-only by explicit design (its own docblock: "carries COUNTS, not
the failure rows"), which is correct for write-back — the records are *in the table*, so
identity is recoverable. For a **handler** terminal it is not. A dropzone that finishes
with "8 succeeded" and no proposal ids cannot link the user to what it just made.

Something must collect what the handlers produced. Without this the dropzone is correct
but unusable.

**Decided — opt-in, per-row storage, bounded on the summary.** Collection is opt-in
(`->collectResults()`); nothing accumulates unless asked. The handler's return value is
what gets collected and must be serialisable (ids, not models) so it survives the queued
path. It is stored per row, and the summary exposes a capped list plus a `truncated` flag.
Storage details are the implementation spec's call (and overlap spec 42 §4.1 references).

### 4.2 No queue targeting anywhere — high conviction, small change

There is no `onQueue`, no `onConnection` and no throttle in `QueuedRunner`,
`BatchRunConfig` or `config/`. Every dispatched chunk lands on the default queue of the
default connection. For calls this expensive, one dropzone of twenty invoices starves
every other job in the application. This is a precondition for "one job per invoice"
being sane, not a nice-to-have.

**Decided:** `onQueue` / `onConnection` go in. Concurrency: an optional `->throttle()`
backed by Laravel's `RateLimited` job middleware, off by default; per-queue worker counts
in the app's queue config remain the primary lever.

### 4.3 Solaris should not own staging — see §5

### 4.4 There is a third outcome state, and we should decline to model it

Pacioli's `needs_relation` and `needs_correction` are **successes that need attention** —
the proposal exists, is visible, and cannot be approved as it stands. Solaris's outcome
model is binary (succeeded / failed, plus discarded).

**Decided: do not add a state.** It is domain semantics and the handler owns it.
But it leaks in exactly one place — a completion notification reading "8 succeeded" when
three need relations is untrue in a way the user will notice. The cheap honest fix is
letting the app contribute its own notification text, not a new core state:
`->completionMessage(fn (BatchSummary $summary, array $results) => …)`, fed by the §4.1
result collection. Recorded here so it is a decision rather than an oversight.

### 4.5 Do not own provenance — a constraint, not a feature

Pacioli deliberately records the model's discarded value, confidence and reasoning beside
the decision it lost to. Our job is to deliver the structured output **faithfully** and
get out of the way.

Implication: whatever a per-row handler receives must be the **raw** model output —
untouched, unnormalised, unsanitised. This is already true (sanitizers apply in
`RecordWriter`, on the write-back path only). Written down so the handler terminal does
not regress it.

---

## 5. What Pacioli says about spec 39's staging

Spec 39 proposes a `SolarisStagedRecord` table as the staging store for
stage → preview → refine → approve → write, with the provisional call being "staging as a
swapped persist callback on `BatchProcessor`" and the table as the store.

**Pacioli already has the staged artifact.** `InvoiceProposal` *is* it: a status enum
(`draft` / `needs_relation` / `needs_correction` / `approved` / `rejected`), findings from
the auditor, per-field provenance, relation matching, a duplicate link, and a review page
built around all of it. A generic `SolarisStagedRecord` would be a second, parallel,
weaker staging concept sitting next to the real one — useless to the consumer it was
meant to serve.

This is evidence **for** spec 39's persist-callback swap (provisional call #1, already
leaning that way) and **against** the table (provisional call #2, currently leaning
"table"). Suggested revision: the swap is the mechanism; the table is an optional default
for apps with no staging of their own, and should not be built until something actually
lacks one.

**Decided (2026-09-24): the persist-callback swap is the mechanism; no staging store for
the handler terminal.** Apps with their own staging (Pacioli) swap the callback and never
touch a Solaris store. But Solaris's *own* generate-side preview (spec 39: full-screen
table + chat, `@row-id` lookups, pagination, surviving a refresh, approve-replays via
`RecordWriter`) is itself a consumer that lacks staging — so a Solaris staging store
**does** ship, as part of implementing spec 39, not before. Its form (table, JSON column
on the `Staged` run, or cache) is spec 39's call. Constraint: the `StagingWriter`
callback receives the same per-row DTO as the handler, so a generic store and an app's
own staging implement the same contract.

---

## 6. Decisions

All resolved 2026-09-24 (Sten + Claude). Detail lives in the referenced sections.

1. **Fan-out: distinct terminal**, not `batchSize(1)`. (§3.1)
2. **Per-row handler payload: a dedicated per-row DTO** (`RowResult`: raw output, source,
   attachments, index, idempotency key), delivered via Filament-style DI. No custom-DTO
   hook for now. (§3.1)
3. **Full definition/run split now** — immutable `AgentDefinition` + transient run.
   Breaking change in 0.5. Definition is a value object; no Solaris registry. (§3.2)
4. **Idempotency is the app's; Solaris helps.** Write terminals stay `tries = 1`;
   handler/fan-out terminals get `->tries(n)` + backoff and a stable per-row key. (§3.1)
5. **Handler results: opt-in `->collectResults()`**, serialisable return values stored
   per row, summary exposes a capped list + `truncated` flag. (§4.1)
6. **Persist-callback swap is the mechanism; no staging store for the handler
   terminal.** Solaris's own staging store ships with spec 39's generate-side preview
   (table / JSON column / cache — decided there). (§5)
7. **Sequencing: definition split first**, then the `AiFormAction` → service routing
   (so it targets the new shape and is not redone), then handler + fan-out. (§7)

Also decided: per-row attachments resolve in-request, not lazily (§3.3); `onQueue` /
`onConnection` + optional off-by-default `->throttle()` (§4.2); no third outcome state,
`->completionMessage()` hook instead (§4.4).

---

## 7. Sequencing

Follows from §6.7. Each step is expected to become its own numbered implementation spec.

1. Agent definition / run split (§3.2) — the 0.5 breaking change, with upgrade note.
2. `AiFormAction` → service routing (spec 38 follow-ups), built on the new shape.
3. Handler terminal on the run (single call first — closes the arc omission).
4. Fan-out execution with per-row attachments + `RowResult`, inline.
5. Queue targeting + optional throttle (§4.2) — small, unblocks the next step.
6. Fan-out queued: `tries`/idempotency key, result collection (§4.1),
   `->completionMessage()` (§4.4).
7. Guidance re-run seam (§3.4).

**Interleaving with spec 42 and spec 39** (agreed 2026-09-24):

1. Step 1 above **and** spec 42 §8 steps 1–4 — independent (42's steps do not touch
   `AiGenerator`), any order or in parallel.
2. Step 2 above (`AiFormAction` routing) — consumes spec 42's wrap/unwrap collaborator and
   targets the definition shape.
3. The per-row collection step in `BatchProcessor`, built **once** — then spec 42 step 5
   (write-back hook) and steps 3–6 above both use it.
4. Spec 39's preview, including its staging store (§5).
