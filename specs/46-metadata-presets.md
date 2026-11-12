# 46 — Per-field metadata: evidence & confidence presets, badges, seeded selection

> **Status:** Approved for implementation (2026-11-12).
> **Date:** 2026-11-11.
> **Context:** Step 3 of [42](42-field-metadata-and-selection.md) §8. Builds on
> [45](45-field-metadata-core.md) (the `FieldMetadata` mechanism) and
> [44](44-per-field-selection.md) (preview selection). Refines 42 §4.2–§4.3 to fit the
> subclass shape spec 45 chose — see "Departures from spec 42".

---

## Goal

Ship two ready-made metadata vocabularies, **evidence** and **confidence**, so an app gets
honest per-field metadata without writing schema keys or prompt language. Make metadata
*visible at a glance* in the preview with a badge beside each field, and let it decide
which fields start ticked when selection is on. Everything stays opt-in; an action without
`->fieldMetadata()` behaves exactly as today.

## API

```php
use Statikbe\FilamentSolaris\Support\Metadata\Presets\EvidenceMetadata;
use Statikbe\FilamentSolaris\Support\Metadata\Presets\ConfidenceMetadata;

AiFormAction::make('analyze')
    ->targetFields(['summary', 'category'])
    ->prompt('Analyze the article.')
    ->withSelectablePreview()
    ->fieldMetadata(EvidenceMetadata::make());                       // ships Evidence

->fieldMetadata(EvidenceMetadata::make(SourceQuality::class))       // your enum = the schema vocabulary
->fieldMetadata(ConfidenceMetadata::make())                         // ships ConfidenceLevel
->fieldMetadata(ConfidenceMetadata::make(MyLevel::class))           // your enum = your cutoffs
->fieldMetadata(ConfidenceMetadata::make(reasoning: true))          // adds a `reasoning` key

// Per-action badge override (any Filament schema component, or null for none):
->fieldMetadataBadge(fn (array $metadata, string $fieldName, mixed $value) =>
    Icon::make(Heroicon::ShieldCheck)->color('success')->tooltip('From source'))
```

There are **no** `->withEvidence()` / `->withConfidence()` shortcuts: one way in,
`->fieldMetadata(...)`.

The metadata itself and its instructions can depend on runtime context through a closure,
like `prompt()` and `targetFields()`:

```php
->fieldMetadata(fn (Article $record) => $record->is_imported
    ? EvidenceMetadata::make()
    : ConfidenceMetadata::make(reasoning: auth()->user()->isEditor()))
->fieldMetadataInstructions(fn (string $locale) => __('ai.evidence_rules', locale: $locale))
```

## Design

### 1. `FieldMetadata` gains two hooks and a variadic `make()`

```php
abstract class FieldMetadata
{
    public static function make(mixed ...$args): static;          // was make(): static

    /** The badge beside the field label; null = none. */
    public function badge(array $metadata): ?Component { return null; }

    /** Whether a selectable field starts ticked in the preview. */
    public function startsSelected(array $metadata): bool { return true; }

    // keys(), instructions(), wrap(), unwrap(), promptFragment(), resolveKeys() — unchanged
}
```

- `Component` is `Filament\Schemas\Components\Component`; `Text` (with `->badge()`) and
  `Icon` are the expected returns.
- `$metadata` is the field's raw metadata as unwrapped (spec 45): strings, numbers, no
  enum instances.
- The defaults keep spec 45 behaviour: a closure-defined metadata (`ClosureFieldMetadata`)
  has no badge and every field starts ticked.
- Subclasses stay stateless after construction, so one instance is safe to share.
- PHP will not let a subclass redeclare `make()` with typed parameters over the variadic
  parent, so presets put their typed options on the **constructor**; the base `make()`
  passes its arguments through to `new static(...$args)`.

### 2. Vocabularies

Two interfaces in `src/Contracts/`:

```php
interface MetadataVocabulary            // implemented by a backed enum
{
    public function promptDescription(): string;   // prompt language for this case
    public function startsSelected(): bool;        // does a field with this case start ticked?
}

interface ConfidenceBand extends MetadataVocabulary
{
    public function threshold(): float;            // lowest score in this band, 0..1
}
```

`promptDescription()` was decided in 42 §9 Q5. `startsSelected()` is new: each case
decides its own starting tick, so a custom enum controls seeding with no extra setting.

Filament's `HasLabel` / `HasColor` are read **if the enum implements them**; otherwise the
badge shows the case name in `gray`. Both shipped enums implement them, with labels
translated (en / fr / nl).

#### Shipped enums (`src/Enums/`)

| Enum | Case | Value | Starts ticked | Color | Prompt description (en) |
|---|---|---|---|---|---|
| `Evidence` | `Verbatim` | `verbatim` | yes | `success` | the value is traceable to an explicit statement in the source |
| | `Derived` | `derived` | no | `warning` | the value is inferred, summarised, reorganised or reformatted from the source |
| `ConfidenceLevel` (threshold) | `High` (0.8) | `high` | yes | `success` | the source states it explicitly |
| | `Medium` (0.5) | `medium` | no | `warning` | the source strongly implies it |
| | `Low` (0.0) | `low` | no | `danger` | a guess with little support in the source |

The prompt descriptions are prompt language, not UI copy, and stay English.

### 3. `EvidenceMetadata`

`EvidenceMetadata::make(string $vocabulary = Evidence::class, bool $reasoning = false)`

- **Validation at construction:** `$vocabulary` must be a backed enum implementing
  `MetadataVocabulary`; otherwise `InvalidArgumentException`.
- **Keys:** `evidence` — the enum's cases as a schema enum, `string` or `integer` by the
  enum's backing type, `->required()`. Reuse the reflection in
  `ModelSchemaResolver::buildBaseType()` (`:96-104`) by extracting it into a small shared
  helper rather than writing a second copy. With `reasoning: true`, also
  `reasoning` — `$s->string()->required()`.
- **Instructions:** built from the cases — one line per case, `` `value`: description `` —
  followed by "If you cannot support a value from the source, return null for the field
  rather than a low-evidence guess." (the hackathon's lesson, 42 §2), plus a reasoning
  line when on: "reasoning: one short sentence explaining the value."
- **Badge:** `Text::make(label)->badge()->color(color)` for the case matching
  `$metadata['evidence']`; null when the value matches no case.
- **startsSelected:** the matching case's `startsSelected()`; `true` when no case matches.

### 4. `ConfidenceMetadata`

`ConfidenceMetadata::make(string $levels = ConfidenceLevel::class, bool $reasoning = false)`

- **Validation at construction:** `$levels` must be a backed enum implementing
  `ConfidenceBand`, with at least one case; otherwise `InvalidArgumentException`.
- **Keys:** `confidence` — `$s->number()->min(0)->max(1)->required()`. The enum is **not** in
  the schema (42 §4.2). `reasoning` as for evidence.
- **Bucketing:** a numeric score is clamped to `0..1`, then gets the case with the highest
  `threshold()` that the score reaches. A score below every threshold, or a non-numeric
  value, matches no case.
- **Instructions:** "confidence: a number from 0 to 1." followed by one line per case,
  highest threshold first — `` - {threshold} or higher: description `` — plus the reasoning
  line when on.
- **Badge / startsSelected:** as for evidence, on the bucketed case.

**To verify during implementation:** whether the structured-output providers Solaris
supports through `laravel/ai` (OpenAI strict mode first) accept `minimum` / `maximum` on a
number. If any rejects them, drop them from the schema; the clamp already covers
out-of-range scores.

### 5. `->fieldMetadataBadge()` on `AiFormAction`

`fieldMetadataBadge(?Closure $badge): static` overrides the metadata's `badge()` for this
action, exactly as `fieldMetadataInstructions()` overrides `instructions()`. Evaluated
through the action's `evaluate()`, with `$metadata`, `$fieldName` and `$value` by name plus
Filament's usual injections. `$value` is the transformed form value, as stored in
`solarisPreviewData['values']`, not the raw model output. Works with presets and with closure-defined metadata.

### 5b. Closures on the action methods

All three action methods take a closure evaluated through the action's `evaluate()`, with
Filament's usual injections (`$record`, `$livewire`, …) — the two-tier model of
`prompt()` / `sourceFields()` / `targetFields()`.

- **`fieldMetadata(Closure|FieldMetadata|null)`** — the closure's **return value** decides:
  - an **array** → the keys, wrapped in `ClosureFieldMetadata` (spec 45's form).
    `JsonSchemaTypeFactory` is injected by type, so `fn (JsonSchemaTypeFactory $s) => [...]`
    keeps working whatever the parameter is called;
  - a **`FieldMetadata`** → that instance, e.g. a preset picked at runtime;
  - **null** → metadata off for this run;
  - anything else → `InvalidArgumentException`.

  This replaces spec 45's "a closure is always the keys". Spec 45 is unreleased
  (CHANGELOG *Unreleased*), so nothing shipped breaks.
- **`fieldMetadataInstructions(Closure|string|null)`** — evaluated when the prompt is built;
  same injections as `prompt()` (`$sourceData`, `$userInput`, `$locale`, …).
- **`fieldMetadataBadge(?Closure)`** — §5.

**No closures inside presets.** `make()` arguments are plain values: a preset has no
evaluation context and stays a stateless, shareable object. Runtime choice goes through the
`fieldMetadata()` closure, which can build any preset with any arguments.

**Evaluated more than once per run.** The `fieldMetadata()` closure is evaluated when the
prompt and schema are built, when the preview renders (badges, seeding), on every
refinement and on Accept. It must return the same answer throughout one run — true for any
closure that depends on `$record` / `$livewire` / the form. Documented, not cached.

### 6. Preview rendering

- Badges are resolved **server-side when the modal renders** (`HasPreviewModal`), only for
  fields with non-empty metadata: the action's closure if set, else the metadata's
  `badge()`. Each component is rendered to HTML inside a minimal
  `Schema::make($livewire)` and passed to `preview-fields.blade.php` as a `badges` prop
  (`fieldName => Htmlable`). The badge HTML is never stored in `solarisPreviewData`. The
  wrapper must render inline beside the label (no grid/column layout); how is left to the
  builder.
- The **info icon stays** beside the badge with its full-metadata tooltip (spec 45), so a
  custom badge never hides the other keys.
- Two spec 45 minors are fixed here: the info icon becomes keyboard-reachable
  (`tabindex="0"`) with an accessible label; array / JSON values in the tooltip are cut to
  the same length as strings.
- Escaping: the badge label and tooltip are escaped by Filament (`allowHTML: false`).

A throwaway spike (2026-11-11) confirmed `Text::make()->badge()->color()->icon()->tooltip()`
and `Icon::make()` render through `Schema::make($livewire)->components([...])->toHtml()`
outside a form.

### 7. Seeded selection

Only when `withSelectablePreview()` is on. With selection off, badges show and nothing else
changes.

- **First preview** (`storePreviewData()`): `solarisPreviewSelected` holds each selectable
  field whose `startsSelected($metadata[$field] ?? [])` is true. A field without metadata
  starts ticked.
- **Refinement** (`updatePreviewData()`): for each selectable field —
  - **value changed** (strict `!==` against the previous preview value) or **newly
    selectable** → its tick comes from `startsSelected()` on the new metadata;
  - **value unchanged** → the user's current tick is kept;
  - a field that is no longer selectable drops out.

  Without metadata on, the rule falls back to spec 44's `reseed()` behaviour exactly.
- If every field starts unticked, Accept is disabled (spec 44's `requiresSelection`) — a
  nudge, not a block: the user can tick and accept. Low confidence never blocks (42 §2,
  Pacioli).
- `solarisPreviewSelected` is still untrusted on Accept; `PreviewSelection::filter()`
  against the server's `selectable` is unchanged.

### 8. Unchanged

- `afterFieldsWritten()` and `solarisPreviewData['metadata']` carry the raw metadata, not
  enum instances.
- `fieldMetadataInstructions()` still replaces a preset's instructions entirely.

## Departures from spec 42

- **No `withEvidence()` / `withConfidence()`** (42 §4.2). Presets are `FieldMetadata`
  subclasses passed to `->fieldMetadata()`, the shape spec 45 chose.
- **Badges are Filament components** returned by a hook / closure, not label + colour read
  only from `HasLabel` / `HasColor`. Those contracts remain the presets' default source.
- **`startsSelected()` on the vocabulary.** 42 §4.3 said "verbatim / above the confidence
  threshold start ticked"; the case now says so itself. Shipped: only `Verbatim` and `High`.
- **`reasoning: true`** on both presets instead of combining presets; a general
  composition of metadata sets is left out.
- **`fieldMetadata()` closures** return keys, a `FieldMetadata` or null (refines spec 45).

## Testing

- **Vocabulary validation** — a non-enum, a unit enum, or an enum without the interface
  throws at `make()`.
- **Evidence schema** — string-backed and int-backed enums produce the matching enum type;
  `reasoning: true` adds a required `reasoning`; all keys required.
- **Confidence schema** — `confidence` is a required number with `min 0` / `max 1` (or
  without, per the strict-mode check).
- **Bucketing** — boundaries (exactly 0.8 → High, 0.79 → Medium), clamping (1.3 → High,
  −0.2 → Low), below every threshold with a custom enum → no case, non-numeric → no case.
- **Instructions** — built from the cases (custom enum's descriptions appear); the null
  guidance for evidence; reasoning line only when on; `fieldMetadataInstructions()` still
  overrides.
- **Badges** — preset badge label/colour from `HasLabel` / `HasColor`, case name + gray
  without them; no badge for an unmatched value; `fieldMetadataBadge()` overrides and
  receives `$metadata`, `$fieldName`, `$value`; a closure returning null shows no badge;
  rendered HTML appears in the modal beside the label and the info icon is still there.
- **Seeding** — first preview ticks only `startsSelected` fields; a field without metadata
  starts ticked; closure metadata ticks everything; selection off ignores seeding.
- **Refinement** — changed value re-seeds from new metadata; unchanged value keeps the
  user's tick (both directions); newly selectable field seeds; without metadata the
  spec 44 behaviour is unchanged.
- **Accept** — everything seeded unticked disables Accept; ticking one writes it.
- **Info icon** — `tabindex="0"` and an accessible label; long JSON values cut.
- **Closures** — `fieldMetadata()` closure returning keys (with `$s` and with another
  parameter name), a preset (chosen from `$record`), null (schema and prompt unchanged, no
  badges, all ticked), and an invalid value (throws); `fieldMetadataInstructions()` closure
  receives `$locale` / `$sourceData`; the existing spec 45 closure tests keep passing.

## Docs

`documentation/ai-form-action.md` › Per-field Metadata: a "Presets" subsection (evidence,
confidence, custom enums, `reasoning:`), "Badges" (`badge()` override and
`fieldMetadataBadge()`), "Which fields start ticked", and "Choosing metadata at runtime"
(closures, incl. the same-answer-per-run rule). `documentation/testing.md`:
preset fakes return envelopes with `evidence` / `confidence`. CHANGELOG *Unreleased*.

## Out of scope

- `annotateFields()` — form-field hints for the no-preview path (42 step 4).
- Combining several metadata sets on one action.
- An expandable details view of all metadata in the preview.
- Metadata on `AiGenerator` / `AiGenerateAction`.
- Persisting metadata (42 §4.4).
