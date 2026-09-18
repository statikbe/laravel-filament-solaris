# 41 — Handler terminal, agent reuse & per-row attachments (provisional)

> **Status:** PROVISIONAL — an assessment to refine, not a design to implement. It
> records what a second real consumer (Pacioli) demands of the generation engine, which
> capabilities we have agreed we want, and which design choices are still open. Expected
> to be rewritten in place as we work through §3 and §6, and only then to spawn numbered
> implementation specs.
> **Date:** 2026-09-10. **Author context:** Sten + Claude.
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

**Open — what does a per-row handler receive?** Today handler-mode gets a raw `array`
(custom schema) or a `BatchResponse` (forModel). Neither fits per-row. It wants this
row's parsed output, the source row it came from, and the attachment that was sent.
Reusing `BatchResponse` would be a category error — that is a batch shape.

**Open — is fan-out a distinct terminal, or `batchSize(1)`?** Leaning **distinct**. "N
rows in one prompt, reconcile by identifier" and "one call per row" are different
machines: different failure semantics, different retry semantics, and fan-out needs none
of the `## Records` identifier-echo contract. Expressing it as `batchSize(1)` hides that.
Cost of a distinct terminal: another concept on an already-wide API.

**Open — retry semantics.** Write terminals run `tries = 1` because `createRecords` is not
idempotent. A handler's idempotency is the app's business (Pacioli's `StagedDocument` is
content-addressed with a `used_at` marker, so it can be made safe). Do we let the caller
choose `tries`, or keep 1 and let the app dedupe?

### 3.2 Reusable agent

*Agreed:* one configured agent should serve several call sites — in Pacioli's case a
single-upload action, a dropzone, an intake listener and a re-propose action.

**The hazard today:** `AiGenerator` is a *mutable* fluent builder. Sharing a configured
instance across call sites is a footgun, not a feature — the second caller mutates the
first's config.

**Open — immutability or a definition/runner split?** Leaning **split**: a definition
object (prompt + schema + tools + provider + options) that *produces* runs, so "same
agent, several terminals" is structural rather than conventional. Clone-on-set is the
smaller change but leaves one class doing both jobs. This is probably the highest-leverage
decision in the whole document: it is also the natural anchor for the chat work (§3.4)
and for a single testing seam.

**Open — registration.** Is a definition an injectable service the app registers once, a
value object it constructs where needed, or both?

### 3.3 Per-row attachments

*Agreed:* each row carries its own attachment(s). One job per invoice is acceptable —
per-invoice LLM processing is expensive enough that per-row jobs are the right grain
anyway.

This gets substantially simpler under fan-out: the attachment travels in the **chunk
descriptor**, not in run-level config. Note the existing queue-serialisation guard
(`AiGenerator.php:531`, `:538`) already handles disk-backed files correctly — Pacioli's
`StagedDocument` stores via `Storage::disk('local')` and serialises fine; the guard only
rejects raw filesystem paths.

**Open — resolution timing.** Resolved in-request at dispatch (current behaviour for
run-level attachments) or lazily on the worker from a reference? In-request is safer and
matches today; lazy is cheaper for large fan-outs.

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

Something must collect what the handlers produced. **Open:** a bounded collection on the
summary, a column on the run row, or an explicit opt-in so large runs don't accumulate?
Without this the dropzone is correct but unusable.

### 4.2 No queue targeting anywhere — high conviction, small change

There is no `onQueue`, no `onConnection` and no throttle in `QueuedRunner`,
`BatchRunConfig` or `config/`. Every dispatched chunk lands on the default queue of the
default connection. For calls this expensive, one dropzone of twenty invoices starves
every other job in the application. This is a precondition for "one job per invoice"
being sane, not a nice-to-have.

**Open:** does a concurrency limit belong here too, or is that the app's queue config?

### 4.3 Solaris should not own staging — see §5

### 4.4 There is a third outcome state, and we should decline to model it

Pacioli's `needs_relation` and `needs_correction` are **successes that need attention** —
the proposal exists, is visible, and cannot be approved as it stands. Solaris's outcome
model is binary (succeeded / failed, plus discarded).

**Recommendation: do not add a state.** It is domain semantics and the handler owns it.
But it leaks in exactly one place — a completion notification reading "8 succeeded" when
three need relations is untrue in a way the user will notice. The cheap honest fix is
letting the handler contribute its own notification text, not a new core state. Recorded
here so it is a decision rather than an oversight.

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

---

## 6. Open questions

Decision-critical, unanswered as of writing.

1. **Fan-out as a distinct terminal, or `batchSize(1)`?** (§3.1) — shapes the whole API.
2. **What payload does a per-row handler receive?** (§3.1)
3. **Definition/runner split, or clone-on-set immutability?** (§3.2) — highest leverage.
4. **Who owns handler idempotency, and can callers set `tries`?** (§3.1)
5. **How do handler results surface in the run outcome, and bounded how?** (§4.1)
6. **Does spec 39's staging table survive §5?**
7. **Sequencing:** does this land before or after the outstanding `AiFormAction` →
   service routing, which is currently the next step of the arc (spec 38 follow-ups)?

---

## 7. Provisional sequencing sketch

Not agreed — a starting point for §6.7.

1. Handler terminal on `AiGenerator` (single call first — closes the arc omission).
2. Fan-out execution with per-row attachments, inline.
3. Queue targeting (§4.2) — small, unblocks the next step being usable.
4. Fan-out queued + result collection (§4.1).
5. Agent definition / reuse (§3.2) — could also lead, if the split changes the shape of
   1–4. **Open.**
6. Guidance re-run seam (§3.4).
