# 45 — Per-field metadata: the core mechanism

> **Status:** Design — agreed in discussion 2026-11-06, awaiting Sten's review before an
> implementation plan.
> **Date:** 2026-11-06.
> **Context:** Step 2 of [42](42-field-metadata-and-selection.md) §8 (Feature B core, §4).
> Refines 42 §4 and §7 — the collaborator is split in two, and the step-2 rendering is a
> tooltip — see "Departures from spec 42".

---

## Goal

Let the model return, per target field, an envelope of `{value, …metadata}` instead of a
bare value. Solaris carries the metadata from the schema to the preview modal and to an
app-facing callback, and never persists it. This step ships the **mechanism only**: the
keys mean nothing to Solaris. The `withEvidence()` / `withConfidence()` presets are step 3.

## API

```php
AiFormAction::make('generate')
    ->targetFields(['summary', 'category'])
    ->fieldMetadata(fn (JsonSchemaTypeFactory $s) => [
        'evidence'  => $s->string()->enum(['verbatim', 'derived'])->required(),
        'reasoning' => $s->string()->required(),
    ])
    ->fieldMetadataInstructions(
        'evidence: "verbatim" when the value is traceable to an explicit statement in the '
        .'source, otherwise "derived". reasoning: one sentence on why.'
    )
    ->afterFieldsWritten(function (array $values, array $metadata) {
        // $values   = ['summary' => '…']
        // $metadata = ['summary' => ['evidence' => 'derived', 'reasoning' => '…']]
    });
```

- `fieldMetadata(Closure|FieldMetadata|null $metadata): static` — either a closure
  returning the metadata keys as `array<string, Type>` (inline, one-off), or a
  `FieldMetadata` instance (a reusable preset, see below). Instances only — no class-string
  form; `::make()` is explicit and matches the prompt presets. `null` turns metadata off.
  Off by default.
- `fieldMetadataInstructions(?string $instructions): static` — prompt language explaining
  the keys, for the inline closure form. Optional; without it the model has only the key
  names and their schema descriptions to go on. Given together with an instance, it
  overrides that instance's `instructions()`.

**Reusable presets.** An app defines a metadata vocabulary once and reuses it across
actions by extending `FieldMetadata` — the same subclass-for-reuse pattern as the prompt
presets in `src/Prompts/Presets/` and spec 43's `setUp()`:

```php
class AuditTrailMetadata extends FieldMetadata
{
    public function keys(JsonSchemaTypeFactory $s): array
    {
        return [
            'evidence'  => $s->string()->enum(['verbatim', 'derived'])->required(),
            'reasoning' => $s->string()->required(),
        ];
    }

    public function instructions(): ?string
    {
        return 'evidence: "verbatim" when the value is traceable to an explicit '
            .'statement in the source, otherwise "derived". reasoning: one sentence on why.';
    }
}

AiFormAction::make('generate')->fieldMetadata(AuditTrailMetadata::make());
```

The step-3 presets ship as subclasses of the same base (e.g. `EvidenceMetadata`), with
`withEvidence()` as shorthand — so an app can also extend a shipped preset and tweak its
instructions.
- `afterFieldsWritten(Closure $callback): static` — see §5. Independent of metadata: it is
  also the general "fields were just written" hook `AiFormAction` lacks today
  (42 §4.4); `$metadata` is `[]` when metadata is off.
- Works with and without `withPreview()`, with `conversational()`, and with
  `withSelectablePreview()`. `AiFormAction` only (see "Out of scope").

## Design

### 1. Two collaborators, no action state

Both live in `src/Support/`, depend on nothing from Filament or Livewire, and are tested
without an action. That is the acceptance criterion from 42 §7.

```php
// src/Support/FactorySchema.php
final class FactorySchema
{
    /**
     * @param  array<string, ComponentFactory>  $factories
     * @return Closure(JsonSchemaTypeFactory): array<string, Type>
     */
    public static function resolver(array $factories): Closure;
}
```

The factories → schema-closure conversion `SolarisAgent::schema()` does inline today,
lifted out. `SolarisAgent::schema()` calls it for its factory branch, so there is one
implementation. It is also the piece the later routing step needs:
`AiGenerator::runSingleStructuredCall()` only takes a schema closure.

```php
// src/Support/Metadata/FieldMetadata.php
abstract class FieldMetadata
{
    public static function make(): static;

    /** The metadata keys added beside `value` in every envelope. */
    abstract public function keys(JsonSchemaTypeFactory $schema): array; // array<string, Type>

    /** Prompt language explaining the keys; null for none. */
    public function instructions(): ?string;

    /**
     * Wrap every field of a schema in an envelope:
     * {value: <original type>, ...keys}, as ->nullable()->required().
     *
     * @param  Closure(JsonSchemaTypeFactory): array<string, Type>  $resolver
     * @return Closure(JsonSchemaTypeFactory): array<string, Type>
     */
    public function wrap(Closure $resolver): Closure;

    /** @param  array<string, mixed>  $response */
    public function unwrap(array $response, array $fieldNames): UnwrappedResponse;

    /** The prompt fragment appended when metadata is on (§3). */
    public function promptFragment(): string;
}

// src/Support/Metadata/ClosureFieldMetadata.php — backs the inline closure form
final class ClosureFieldMetadata extends FieldMetadata
{
    /** @param  Closure(JsonSchemaTypeFactory): array<string, Type>  $keys */
    public function __construct(private Closure $keys, private ?string $instructions = null) {}
}

// src/Support/Metadata/UnwrappedResponse.php
final readonly class UnwrappedResponse
{
    /**
     * @param  array<string, mixed>  $values     plain values, keyed by field
     * @param  array<string, array<string, mixed>>  $metadata  keyed by field
     */
    public function __construct(public array $values, public array $metadata) {}
}
```

**Base class, not a final value object.** `wrap()`, `unwrap()` and `promptFragment()` are
concrete on the base and not meant to be overridden; subclasses supply `keys()` and
optionally `instructions()`. Subclasses stay stateless — methods, no mutable properties —
so one instance is safe to reuse across actions. The action stores the closure form as a
`ClosureFieldMetadata`, so the pipeline only ever sees a `FieldMetadata`.

**Why it wraps a closure, not factories** (departure from 42 §7): wrapping is a decorator
over *any* schema — the form path's factory schema today, `AiGenerator`'s `schema()` or a
`forModel()` schema later. A factory-taking collaborator would only ever serve the form
path.

**`wrap()` rules.**
- The envelope is `object(['value' => <original>, ...keys])->nullable()->required()` —
  both are needed by OpenAI strict mode (42 §4.1, learned the hard way by the hackathon).
- A metadata key named `value` is a configuration error (`InvalidArgumentException`,
  thrown when the closure is first resolved).
- The keys' own `->required()` is the caller's business, as in 42 §4.2's example; Solaris
  does not force it.

**`unwrap()` rules.**
- Only the names in `$fieldNames` (the target fields) are unwrapped. Every other key —
  notably the conversational `_message` — passes through into `values` untouched.
- A well-formed envelope → `values[field] = envelope['value']`, `metadata[field]` = the
  remaining keys.
- A `null` envelope, a non-array, or an array without a `value` key → `values[field] =
  null` and no metadata. `transformResults()` already turns `null` into a failed field.
- Metadata is returned raw — no normalising, no sanitising (42 §6).

### 2. Wiring into the form pipeline

`HasPromptPipeline` gains a `getFieldMetadata(): ?FieldMetadata` hook returning `null`;
`AiFormAction` overrides it — the same pattern as `getSelectablePreviewFields()` (spec 44).

When it returns an instance, both arms of the pipeline (`runPipeline()` and `refine()`)
configure the agent with a wrapped resolver instead of the factory map:

```php
$agent->configure($prompt, [], $metadata->wrap(FactorySchema::resolver($factories)));
```

and unwrap before transforming:

```php
$unwrapped = $metadata->unwrap($aiResponse, array_keys($factories));
$result = $this->transformResults($unwrapped->values, $factories);   // unchanged
$fieldMetadata = array_intersect_key($unwrapped->metadata, $result['values']);
```

- `transformResults()` does not change.
- **Metadata of failed fields is dropped** (the `array_intersect_key`): a field that ended
  up failed — `null`, or a throwing `toFormValue()` — shows nothing and reaches no callback.
- Sanitizers keep applying to the value only, exactly as today.
- `ConversationalSolarisAgent` adds `_message` on top of `parent::schema()`, so it works
  unchanged on a resolver and stays outside the envelopes.
- When metadata is off, the pipeline is untouched: factories in, as today.

### 3. The prompt

When metadata is on, `promptFragment()` is appended to the composed prompt in
`buildPrompt()`. It is a short fixed sentence explaining the envelope ("Each field is an
object: put the field's content in `value` and fill the other keys as described below."),
followed by the instructions when given. Translated via the package's lang files like the
other prompt chrome.

### 4. Preview modal

`storePreviewData()` adds `metadata` (the per-field map from §2) to `solarisPreviewData`
when metadata is on; `updatePreviewData()` replaces it on every refinement. The key is
absent when metadata is off — same convention as spec 44's `selectable`.

`preview-fields.blade.php` takes a `metadata` prop (default `null`). For a field with
metadata, an info icon sits beside the label in the `<dt>` with an `x-tooltip` listing
the keys:

- one `key: value` line per key, in key order;
- strings cut at ~150 characters with an ellipsis;
- arrays / objects as compact JSON; booleans and `null` spelled out;
- plain text only. If Filament's `x-tooltip` collapses line breaks, the keys are joined
  with ` · ` instead — to be settled during implementation.

Fields without metadata render exactly as today. Selection checkboxes (spec 44) and the
icon sit side by side. Metadata does **not** seed which boxes start ticked — that needs
the vocabulary's meaning and is step 3.

### 5. `afterFieldsWritten()`

Called after `writeResults()` has set the form values, on both paths:

- **No preview:** right after the write, with the transformed values and their metadata.
- **Preview:** on Accept, with only what was actually written — spec 44's filtered values
  — and the metadata for exactly those fields.

Not called when nothing is written (everything deselected, or every field failed).
Evaluated through Filament's `evaluate()`, so the closure gets `$values` and `$metadata`
by name plus the usual injections (`$livewire`, `$record`, …).

**Trust:** in the preview path the metadata comes back from `solarisPreviewData`, which
the client can rewrite — the same is already true of `values`. Metadata is the model's
testimony, not fact (42 §2); a consumer persisting it as an audit trail should treat it
as such. Documented, not engineered around.

## Departures from spec 42

- **§7's single factories-in collaborator is split** into `FactorySchema` (factories →
  schema closure) and `FieldMetadata` (wrap/unwrap over any schema closure). §1 says why.
- **§4.1 unwraps "in `transformResults()`"**; here unwrapping happens *before* it, so
  `transformResults()` stays unchanged.
- **§4.3's badge** becomes an info icon with a tooltip for this step. Badges with label
  and colour come with the step-3 presets, which know what a value means. A fuller view
  of all metadata — an expandable "details" section under each field rather than a nested
  modal — is left for later.
- **§4.2's presets get a home for app-defined ones.** 42 only plans the two shipped
  presets; here `FieldMetadata` is an extendable base, so apps write their own reusable
  vocabularies and the shipped presets become subclasses of it.
- **§4.4's unnamed `AiFormAction` callback** is `afterFieldsWritten()`, mirroring §5's
  `afterRecordsWritten()`.

## Testing

- **`FactorySchema`** — produces the same types `SolarisAgent::schema()` did; the existing
  agent schema tests keep passing.
- **`FieldMetadata::wrap()`** — each field becomes a nullable, required object holding
  `value` plus the keys; a key named `value` throws; wraps a hand-written resolver, not
  only a factory one.
- **Presets** — a `FieldMetadata` subclass passed via `::make()` drives the schema and the
  prompt; the closure form becomes a `ClosureFieldMetadata`; `fieldMetadataInstructions()`
  overrides an instance's `instructions()`.
- **`FieldMetadata::unwrap()`** — well-formed envelope; `null` envelope; non-array; missing
  `value`; non-target keys (`_message`) pass through; metadata returned raw.
- **Pipeline** (Livewire fixture, `AiFormAction::fake()` returning envelopes) — values land
  in the form unwrapped; failed fields carry no metadata; sanitizers see only the value;
  metadata off leaves the schema and behaviour unchanged.
- **Preview** — `metadata` stored and replaced on refinement; the icon and tooltip render
  for fields with metadata and not for others; selection still works alongside.
- **`afterFieldsWritten()`** — called on the no-preview path; on Accept with only the ticked
  fields and their metadata; not called when nothing is written; `$metadata === []` when
  metadata is off.
- **Prompt** — the fragment and instructions are appended only when metadata is on.

## Out of scope

- The `withEvidence()` / `withConfidence()` presets, `MetadataVocabulary`, badges with
  label/colour, and metadata-seeded selection — step 3.
- `annotateFields()` (form-field hints for the no-preview path) — step 4.
- Metadata on `AiGenerator` / `AiGenerateAction`. `handleUsing()` already receives the raw
  envelopes (42 §9 Q3); the collaborators are built to be reused there.
- An expandable details view of all metadata in the preview.
- Persisting metadata anywhere (42 §4.4).
