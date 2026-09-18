# Implementation status & open work

> Working index of what is built, what is next, and what is deliberately parked.
> Companion to [`missing-features.md`](missing-features.md) (the raw backlog) and the
> numbered specs in this directory. Architecture diagrams live in
> [`../documentation/architecture.md`](../documentation/architecture.md).
>
> **Last reviewed:** 2026-09-18.

## Repo state

| | |
|---|---|
| Suite | 703 Pest tests, PHPStan level 5 |
| Numbered specs | 41 in `specs/` |
| Release | everything sits under CHANGELOG *Unreleased* |
| Unpushed | `main` is far ahead of `origin/main` (last push 2026-06-12) — the whole AiGenerator arc plus sanitizers |

---

## Track A — the AiGenerator arc

Umbrella: [`36-ai-generator-service.md`](36-ai-generator-service.md). Extract the AI
orchestration engine out of the Filament actions into a headless `AiGenerator`, so
generation is callable from a controller, a listener, a command or a queue worker.

**This track is a real sequence** — each piece depends on the one before it.

| Piece | Status | Notes |
|---|---|---|
| `AiGenerator` owns single + batch, inline + queued (specs 36–38) | ✅ merged | The action is a thin translator; `ProcessChunkJob` shares `RecordWriter` / `RecordsSchemaBuilder` with the inline path |
| Sanitizers — parity port 1 ([spec 40](40-sanitizers.md)) | ✅ merged | Closed the stored-XSS gap on record write-back; `AiFormAction` moved onto the same `Sanitizer` abstraction |
| Route `AiFormAction`'s AI call through the service | ⏭️ **next** | `HasPromptPipeline` still builds its own `SolarisAgent` — the last split-brain in the engine. Non-conversational routing first. No numbered spec yet |
| Presets on the generate side — parity port 2 | queued | Spec 35 Part A, postponed out of spec 40. `tools()` already exists on the service |
| Conversational + preview as shared capabilities ([spec 39](39-conversational-preview-exploration.md)) | 🎨 designing | Approach A + staged-write-on-approve settled; run reuse, guidance distillation and the attachment round-trip still open |
| Handler terminal, agent reuse, per-row attachments ([spec 41](41-handler-terminal-and-agent-reuse.md)) | 🎨 designing | Provisional. Driven by Pacioli as validation case #2; sequencing against the `AiFormAction` routing above is open question §6.7 |
| Headless docs for the batch + queued surface | deferred | Deferred twice (pieces 2 and 3). `documentation/` has no `ai-generator.md` |
| Service-native `AiGenerator::fake()` | deferred | Option 2 from spec 37. Today the fake plugs in through the `responseGenerator()` seam |

### Known gaps in the routing step (no spec covers these yet)

Found while sequencing [spec 42](42-field-metadata-and-selection.md) §7, 2026-09-18:

- **The form fake would be silently bypassed.** `AiGenerator::responseGenerator()` is typed
  as a *batch* seam (`Closure(array): BatchResponse`) and is only consulted by
  `InlineRunner`; the form path records its own calls inside `HasPromptPipeline`. Routing
  `AiFormAction` through `runSingleStructuredCall()` would leave `AiActionFake` seeing
  nothing. **This is the one that has to be solved before routing, not after** — it is how
  every form-action test asserts.
- **The service cannot accept factories.** `runSingleStructuredCall()` hardcodes
  `configure($prompt, [], $schemaResolver)`. Spec 39 names "factory-schema support on the
  service" as in-scope but leaves the shape undecided (service setter vs. action-side
  closure conversion). Spec 42 §7 resolves to build that conversion first, standalone.
- **`runPipeline()` serves both arms**, so routing only the non-conversational one forks it
  into two call paths — temporarily *more* split-brain, not less.
- Smaller deltas: the service throws where `executeAiCall()` returns `null`, and it skips
  `SolarisPromptLogger::log()` / `logAgentSchema()` / `logResponse()`.
- **Docs contradict each other on ordering:** spec 39 §"recommendation" puts the preset
  parity port *before* routing; the table above puts it after. Spec 41 §6.7 lists its own
  position relative to routing as decision-critical and unanswered.

---

## Track B — consumer-driven API gaps

Not sequenced. Each is real friction found by pressure-testing the package against an
actual application; each is picked up when a consumer needs it.

### knxcou — [spec 35](35-aigenerateaction-parity-and-plausibility-review.md) Part B

Spec 35's own advice still stands: prototype the `handleUsing()` version in the consuming
repo first, and let the friction confirm which of these earn their place. Spec 41 §2 notes
a reason to wait — knxcou was never actually built, while Pacioli exists and wants the
opposite.

- `upsertRecords(uniqueBy: […])` — write-back is create *or* update; there is no
  `updateOrCreate` into a different model on a composite business key, so re-running
  duplicates rows.
- `recordAttributes(array|Closure)` — no way to merge constant or derived attributes (an
  FK, a context key) into each written record.
- `identifyBy(string)` — reconciliation keys on the PK or `_index`; a business identifier
  would let synthetic rows round-trip naturally.
- Inexact option matching for enum columns — *maybe*. Structured output already constrains
  enum values; revisit only if enum echoes fail in practice.
- ~~Headless runner~~ — resolved by the AiGenerator arc.

### Pacioli — [spec 41](41-handler-terminal-and-agent-reuse.md)

Agreed as wanted, shaping still open: handler as a real terminal (per-row, queueable),
reusable agent definition, per-row attachments, and a guidance-string re-run seam. Plus
two small high-conviction additions: handler results must surface in the run outcome
(§4.1) and there is **no queue targeting anywhere** — no `onQueue`, no `onConnection`, no
throttle (§4.2).

---

## Track C — backlog and housekeeping

- **Unpushed `main`.** Everything since 2026-06-12. Three remote branches are fully merged
  and can be pruned.
- **Streaming / broadcast responses** (MF #3, P1). Open question is whether streaming
  structured output is feasible at all under a JSON-schema constraint.
- **Cross-session conversation persistence** (MF #11). Blocked: needs a
  `solaris_conversations` morph table, and `laravel/ai` does not rehydrate
  `Message::attachments` into `File[]`. Overlaps spec 39 and spec 41 §3.4.
- **Retry policy knob** (MF #5). `timeout()` shipped; the retry half didn't.
  `laravel/ai`'s `#[Retries(n)]` is available but not exposed.
- **Dictation polish** (MF #4, #8). Extra target fields are silently ignored without a
  prompt builder (should warn); button loading states are still `animate-pulse`.
- **Split `ComponentFactory` file methods into opt-in interfaces** (MF #10). Deliberately
  post-1.0 — strictly additive, and the "must implement all 7" pain is already solved by
  the base's defaults.
- **The identifier-echo contract is unverified against a live model** (spec 27). Every
  test of the `## Records` / `## Instructions` echo contract uses a fake.
- **`missing-features.md` #13 is stale** — specs 24, 26 and 27 have all shipped.
