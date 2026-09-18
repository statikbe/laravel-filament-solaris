# 42 — Per-field metadata & per-field selection

> **Status:** DESIGN — agreed in discussion 2026-09-18, not yet implemented. Expected to
> spawn two numbered implementation specs (see §8).
> **Date:** 2026-09-18. **Author context:** Sten + Claude.
> **Prior art:** `vlwpla` branch `feature/hackaton-solaris` (hackathon, Aurel) and Pacioli's
> field-provenance subsystem. Both are described in §2.
> **Relates to:** [41](41-handler-terminal-and-agent-reuse.md) §4.1 (outcomes carry counts,
> not identities) and §4.5 (do not own provenance), [39](39-conversational-preview-exploration.md)
> (preview), [35](35-aigenerateaction-parity-and-plausibility-review.md) Part B
> (`recordAttributes`).

---

## 1. Two features, deliberately separated

**A. Per-field selection.** The preview modal is all-or-nothing today: Accept writes every
generated value, or you cancel the lot. Per-field selection lets the user tick which fields
actually get written. Useful on its own, with no AI metadata involved. Enabled by an
explicit call, off by default.

**B. Per-field metadata.** The model returns, per target field, an envelope of
`{value, …metadata}` instead of a bare value. Solaris carries that metadata faithfully from
the schema to wherever the consumer can act on it, and renders it where it helps the user
decide. Solaris never persists it.

They interact in exactly one place: when both are on, metadata seeds which boxes start
ticked. Neither requires the other.

---

## 2. What the two prior implementations teach

### vlwpla (hackathon)

Two subclasses and a published Blade, no package changes:

- `ConfidenceWrappingAgent extends SolarisAgent` overrides `schema()` to wrap every field:
  `$schema->object(['value' => $factory->responseSchema($schema), 'evidence' => …])->nullable()->required()`.
- `ConfidenceAwareAiFormAction extends AiFormAction` overrides `createAgent()`,
  `transformResults()` (unwrap, stash an `evidence` map) and `storePreviewData()` (merge the
  map into `solarisPreviewData`, seed the host's selection array).
- A **published** `preview-modal.blade.php` renders a checkbox and a colour-coded badge per
  field — the fork was forced, because `displays[$field]` carries only `label`/`display`/`type`.
- `CreateVacancy` holds `public array $solarisPreviewSelection` and overrides
  `solarisAcceptPreview()` to intersect `values` with the selection.

**The finding that matters:** the branch's last commit *replaces* `confidence: high|medium`
with `evidence: verbatim|derived`, because evidence is verifiable against the source text
while an abstract confidence grade is not. The prompt defines the two kinds operationally
("the cleaned HTML description is ALWAYS derived because you reorganized the source
content") and adds "prefer `null` over a low-evidence guess".

### Pacioli

Numeric `confidence` (0..1) plus free-text `reasoning`, treated as **testimony, never fact**:
the `relation_default > history > llm` ladder nulls them out whenever a deterministic rung
answers; what the model suggested is recorded *beside* the decision in
`proposal_field_decisions`; a denormalised `min_confidence` sorts the review queue; low
confidence **never blocks** an approval. Rendering is per-field via
`->hint()/->hintColor()/->hintIcon(tooltip:)` with reasoning in the tooltip only — it used
to render inline and was "the noisiest element on the screen".

Its own weak spot is worth stealing from: three hard-coded thresholds (0.9 in the prompt,
0.8 in badges, 0.5 in the table) and no enum.

### Conclusions

1. Both want the same *mechanism* and disagree on *vocabulary* and *lifetime*. So Solaris
   ships the mechanism, plus presets for the two common vocabularies.
2. Decision-time metadata belongs in the preview; durable provenance belongs on the form
   field and in the app's own tables.

---

## 3. Feature A — per-field selection

**API.** One toggle on `AiFormAction`, off by default:

```php
AiFormAction::make('generate')
    ->withPreview()
    ->selectableFields();          // or ->selectableFields(false)
```

Requires `withPreview()` — selection without a preview has nothing to select in. Configuring
it without a preview is a config error, matching the existing terminal-validation style at
`AiGenerateAction.php:803-813`.

**Mechanics.** The selection state lives on the Livewire host, because that is what
`wire:model` needs: `InteractsWithSolarisPreview` gains
`public array $solarisPreviewSelection = []`, seeded when preview data is stored (all fields
by default; see §4 for the metadata-seeded case). `solarisAcceptPreview()` filters
`values` by the selection before handing off to `acceptPreview()`.

**Labels.** Deselected fields are *not* failures. `filledLabels` is recomputed from the
surviving values and `failedLabels` is left alone — a deselected field must not surface as
"the AI could not fill this". (The hackathon hit this exact bug and fixed it in
`CreateVacancy::solarisAcceptPreview()`.)

**Rendering.** `preview-fields.blade.php` renders a checkbox per field when selection is
enabled. This must be driven by data in `solarisPreviewData`, not by a published view.

---

## 4. Feature B — per-field metadata

### 4.1 The envelope

When metadata is configured, the response schema wraps each target field:

```json
{ "summary": { "value": "…", "evidence": "derived" }, "category": null }
```

Built by wrapping `ComponentFactory::responseSchema()` rather than replacing it, so every
factory keeps working untouched. The wrapper object is `->nullable()->required()` — both are
required by OpenAI strict mode, and the hackathon needed two follow-up commits to discover
it. `ConversationalSolarisAgent`'s `_message` (`Agents/ConversationalSolarisAgent.php:20-26`)
is the existing precedent for adding to a factory-derived schema.

**Unwrapping** happens in `transformResults()` (`HasPromptPipeline.php:551-577`): `value`
becomes the form value and goes through `applySanitizer()` exactly as today; the remaining
keys are collected into a `metadata` map keyed by field name. A missing or malformed
envelope is a failed field, as a missing value is today.

The unwrap must be shared with `AiGenerator`, not implemented twice — see §7.

### 4.2 The API: mechanism first, presets on top

The mechanism names nothing:

```php
->fieldMetadata(fn (JsonSchemaTypeFactory $s) => [
    'evidence'  => $s->string()->enum(['verbatim', 'derived'])->required(),
    'reasoning' => $s->string(),
])
->fieldMetadataInstructions('…prompt language explaining the keys…')
```

The presets are thin wrappers that supply schema, prompt fragment and rendering — and each
is customised by **passing a backed enum class**, not by editing an array:

```php
->withEvidence()                       // ships Evidence::Verbatim | Evidence::Derived
->withEvidence(SourceQuality::class)   // your cases become the schema enum

->withConfidence()                     // ships ConfidenceLevel with default cutoffs
->withConfidence(levels: MyLevel::class)
```

The two presets customise in **different places**, which is the crux:

- **Evidence — the enum *is* the schema vocabulary.** The cases go straight into the
  response schema. `Type::enum()` already accepts a `class-string<BackedEnum>` and
  `ModelSchemaResolver::buildBaseType()` (`:96-104`) already does the reflection to pick
  string vs int backing; reuse it rather than writing a second one.
- **Confidence — the enum is not in the schema at all.** The model returns a number `0..1`;
  the enum only buckets that number for display, so it carries **cutoffs**, not cases that
  the model ever sees.

**Vocabularies describe themselves.** Case names alone do not produce honest answers — the
hackathon's result turned on the prompt *definitions* ("verbatim means traceable to an
explicit statement in the page"; "the cleaned HTML description is ALWAYS derived"). So a
vocabulary enum implements a small package-owned interface and the preset builds the prompt
fragment by iterating its cases:

```php
enum Evidence: string implements MetadataVocabulary
{
    case Verbatim = 'verbatim';
    case Derived = 'derived';

    public function description(): string { … }   // → prompt language
}
```

Filament's `HasLabel` / `HasColor` are read **if present** and fall back to case names with
no colour otherwise. They are deliberately not required: the package uses neither contract
anywhere today (`Tone`, `ImageQuality`, `BatchRunStatus` are bare backed enums), and
requiring them would pull a Filament UI contract into an otherwise headless concern.

Both presets remain overridable per call; neither is on by default.

### 4.3 Where it shows

**Preview modal (primary).** Metadata renders as a badge beside the field label in
`preview-fields.blade.php`, driven by `solarisPreviewData['metadata']`. Long text
(`reasoning`) goes in a tooltip, not inline — Pacioli's explicit lesson. When selection
(Feature A) is also on, fields the model marked `verbatim` / above the confidence threshold
start ticked and the rest start unticked.

**Form field (opt-in, second surface).** For the no-preview path, an opt-in annotation
writes Filament's `hint()` / `hintColor()` / `hintIcon(tooltip:)` on the target component
after write-back. `helperText()` is deliberately *not* used: `hint()` is the label-adjacent
slot and the only one carrying colour, icon and tooltip together.

Two constraints:

- **Merge, never clobber.** The consumer's own `hint()` is theirs. Only set when empty, or
  append; never overwrite.
- **Not called `hint()`.** `ComponentFactory::hint()` already means the *prompt* hint fed
  into the schema description (`HasTargetFields.php:135`). Proposed: `->annotateFields()`,
  with a `FieldAnnotation` value object (`{color, text, icon, tooltip}`) modelled on
  Pacioli's.

### 4.4 Lifetime: Solaris never persists

Metadata is delivered, not stored. No table, no migration, no `SolarisStagedRecord`
(consistent with [41](41-handler-terminal-and-agent-reuse.md) §4.5 and §5). The lifetimes of
the two known consumers are irreconcilable anyway — Pacioli's decisions are audit records
kept forever, the hackathon's evidence map lives for the duration of one modal.

The cost, stated plainly: **pass-through is only real where a seam exists.** Today
`handleUsing()` gets raw output for free, and `AiFormAction` has *no* app-facing callback at
all — no `after()`, no handler — so the hackathon had to override `solarisAcceptPreview()`
on the Livewire host. This design closes both gaps:

- `AiFormAction` gains a callback receiving the accepted `values` and their `metadata`.
- The write-back terminal gains the hook in §5.

---

## 5. The write-back hook

`createRecords()` / `updateRecords()` build models straight from returned values, so an app
using declarative write-back can never see the metadata. Rather than forbidding the
combination, write-back gains a per-chunk hook:

```php
->afterRecordsWritten(function (array $written) {
    // $written: [['row' => …, 'metadata' => […], 'model' => Model], …]
})
```

**Per chunk, where the write happened** — in-request for `runInline()`, on the worker for
`runQueued()`. Bounded by construction, needs no accumulation, and matches the grain a
consumer wants (Pacioli writes its `proposal_field_decisions` rows right beside the proposal
it just created). A run-level rollup of everything a run produced is explicitly **out of
scope** here; it is [41](41-handler-terminal-and-agent-reuse.md) §4.1 and drags the storage
question back in.

**What it costs.** Identity is currently discarded at the moment of writing:

```php
RecordWriter::write(array|Model $row, array $attrs): void          // RecordWriter.php:37
Closure(mixed, array<string, mixed>): void  $persistRecord         // BatchProcessor.php:20
```

Both must carry the written model back, and `BatchProcessor` must collect the chunk's
triples instead of only incrementing `$succeeded`. This is in the shared core, so it lands on
the inline and queued paths at once — which is the point: the same collection mechanism is
what [41](41-handler-terminal-and-agent-reuse.md) §4.1 needs for the handler terminal.

**Failure semantics.** The write path runs `tries = 1`, so the hook is at-most-once and gets
no retry. It sees only the rows that actually wrote; failures stay in the existing
`failed[]` reconciliation. A throwing hook must not roll back writes that already succeeded.

---

## 6. What Solaris deliberately does not do

- Persist metadata, or define a table for it.
- Decide anything based on it — no auto-accept, no blocking, no validation. It informs the
  user and the consumer; it never gates.
- Normalise or sanitise it. A handler must receive the model's raw claim
  ([41](41-handler-terminal-and-agent-reuse.md) §4.5). Sanitizers continue to apply to the
  `value`, on the write path only.
- Ship a run-level result collection (§5).

---

## 7. Shared implementation, not a second engine

The wrap/unwrap must live somewhere both `HasPromptPipeline` and `AiGenerator` use — a small
collaborator owning "wrap these factory schemas in an envelope" and "unwrap a response into
values + metadata". Building it inside `HasPromptPipeline` would deepen exactly the
split-brain that the outstanding `AiFormAction` → service routing step exists to remove
([status.md](status.md), Track A).

**Sequencing question, open:** does this land before or after that routing step? Building it
after means writing it once. Building it before means writing it in the shared collaborator
and wiring the second caller later — acceptable, but only if the collaborator is genuinely
independent of the action.

---

## 8. Suggested sequencing

1. **Feature A, per-field selection** — standalone, no AI involvement, immediately useful,
   and it establishes the `solarisPreviewData` → checkbox plumbing.
2. **Feature B core** — envelope wrap/unwrap in the shared collaborator, `metadata` on
   preview data, badges in `preview-fields.blade.php`, the `AiFormAction` callback.
3. **The two presets** — `withEvidence()`, `withConfidence()` + `ConfidenceLevel`.
4. **Field annotation** (`annotateFields()`) — the second surface, for the no-preview path.
5. **The write-back hook** — depends on the `RecordWriter` / `BatchProcessor` change, and
   overlaps [41](41-handler-terminal-and-agent-reuse.md) §4.1; sequence against spec 41.

## 9. Open questions

1. Names: `fieldMetadata()`, `annotateFields()`, `afterRecordsWritten()`, `selectableFields()`
   are all provisional.
2. §7 sequencing against the `AiFormAction` → service routing step.
3. Does `AiGenerateAction`'s `handleUsing()` receive metadata as a separate argument, or
   simply as part of the raw output it already gets? (Leaning: it already has it — do
   nothing.)
4. ~~Should the confidence preset's thresholds be config-level, per-action, or both?~~
   **Resolved (2026-09-18):** neither — the cutoffs live on the level enum, and swapping the
   enum class is how you move them. See §4.2.
5. The vocabulary interface name (`MetadataVocabulary`) and whether `description()` is the
   right shape for generating prompt language.
