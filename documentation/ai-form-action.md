# AiFormAction API

[← Back to README](../README.md)

`AiFormAction` extends Filament's `Action` class and adds three concerns via traits: `HasSourceFields`, `HasTargetFields`, and `HasUserInput`.

## Source Fields

Source fields are the form fields whose values are read and sent to the AI as context.

```php
AiFormAction::make('summarize')
    ->sourceFields(['title', 'body', 'author'])
```

Use `sourceScope()` to transform a source value before it reaches the prompt. This is useful for truncating long content or formatting values:

```php
AiFormAction::make('summarize')
    ->sourceFields(['title', 'body'])
    ->sourceScope('body', fn (string $value) => str($value)->limit(3000)->toString())
```

## Target Fields

Target fields are the form fields that receive the AI response. The package auto-detects the Filament component type for each target field and instantiates the appropriate `ComponentFactory`.

```php
// Single target
AiFormAction::make('classify')
    ->targetField('category_id')

// Multiple targets
AiFormAction::make('fill')
    ->targetFields(['summary', 'category_id', 'tags'])
```

Use `targetScope()` to constrain relationship-based options (e.g., filter a Select's relationship query):

```php
AiFormAction::make('classify')
    ->targetField('category_id')
    ->targetScope('category_id', fn ($query) => $query->where('active', true))
```

Use `targetHint()` to append a behavioural nudge to a specific field's JSON-schema description — the hint is sent to the AI alongside the auto-generated schema, so it shapes how the field gets filled without rewriting the whole prompt:

```php
AiFormAction::make('fill')
    ->targetFields(['summary', 'tags'])
    ->prompt('Read the body and fill the fields.')
    ->targetHint('summary', 'Keep it under 80 words. No marketing language.')
    ->targetHint('tags', 'Pick 3–5 short, lowercase, hyphen-separated tags.');
```

Hints compose with the structural description the factory generates from the component (e.g. for a `Select`, the enum of allowed values). They're particularly useful for narrowing tone, length, or formatting per field without bloating the main prompt.

For Select/CheckboxList targets you can also tune the option-matching fallback per field — see [Component Factories → Option Matching](factories.md#option-matching):

```php
AiFormAction::make('classify')
    ->targetFuzzyMatching('billing_code', false)   // disable fuzzy for a high-stakes field
    ->targetFuzzyThreshold('city', 0.15);          // stricter threshold for another
```

## Prompts

There are three ways to provide a prompt:

**Inline string** — wrapped in the base prompt template with source data and JSON schema automatically appended:

```php
->prompt('Classify the content into the most appropriate category.')
```

**Blade view** — full control over the prompt template. Receives all standard variables (`$sourceData`, `$factories`, `$responseSchema`, `$record`, `$locale`, `$localeName`, `$userInput`):

```php
->prompt(view('prompts.my-custom-prompt'))
```

**Preset** — a pre-built prompt builder for common tasks:

```php
->preset(SummaryPreset::make()->maxWords(150)->tone('professional'))
```

## Presets

Presets are reusable prompt builders for common AI tasks. Each preset renders its own Blade template with configurable parameters. See [Presets Reference](presets.md) for the full API.

## User Input

User input opens a modal before the AI executes, allowing the end user to provide additional instructions or make selections. The user's input is included in the prompt.

```php
use Statikbe\FilamentSolaris\Support\UserInput;

AiFormAction::make('generate')
    ->sourceFields(['title'])
    ->targetField('body')
    ->preset(GenerationPreset::make())
    ->userInput(
        UserInput::make()
            ->prompt('What should the AI write about?')
            ->placeholder('Describe the content you want...')
    )
```

`UserInput` defaults to a single textarea with the name `user_instructions`. For custom form fields:

```php
->userInput(
    UserInput::make()->fields([
        Select::make('tone')
            ->options(['formal' => 'Formal', 'casual' => 'Casual'])
            ->required(),
        TextInput::make('keywords')
            ->placeholder('comma-separated keywords'),
    ])
)
```

Some presets define their own default user input (e.g., `GenerationPreset` adds a "What should the AI write?" textarea, `TranslationPreset` adds a language selector). Use `withDefaultUserInput()` to enable it:

```php
AiFormAction::make('translate')
    ->sourceFields(['body'])
    ->targetField('body_nl')
    ->preset(TranslationPreset::make()->language('nl'))
    ->withDefaultUserInput()
```

`withDefaultUserInput()` is order-independent — it can be called before or after `->preset()` / `->prompt()`; the preset's default is resolved lazily at render time. An explicit `->userInput()` always takes precedence over the preset default.

## Locale

By default the application locale (`app()->getLocale()`) is used as a hint in the prompt. Override per-action:

```php
->locale('nl')
```

## Provider & Model

Override which AI provider and model are used per-action. When not set, the package falls through a resolution chain: action → preset → config `preset_providers` → config `default_provider` → `laravel/ai` default. See [Configuration](configuration.md) for details.

```php
// Single provider + model
AiFormAction::make('summarize')
    ->provider('anthropic', 'claude-sonnet-4-5-20250514')

// Failover array — tries providers in order on failure
AiFormAction::make('summarize')
    ->provider(['openai' => 'gpt-4o', 'anthropic'])

// Closure (Filament convention)
AiFormAction::make('classify')
    ->provider(fn () => config('my-app.ai_provider'))

// On a preset
->preset(SummaryPreset::make()->provider('openai', 'gpt-4o-mini'))
```

## Timeout

Set the HTTP timeout in seconds for the AI call. Defaults to the `laravel/ai` default (60s) when not configured.

```php
AiFormAction::make('summarize')
    ->timeout(120)  // 2 minutes for long content

AiFormAction::make('classify')
    ->timeout(30)   // quick classification
```

A package-wide default can be set in the config (`default_timeout`). See [Configuration](configuration.md).

> [!NOTE]
> **AI calls run synchronously inside the Livewire request.** The preview modal's "loading" spinner is still a blocking HTTP request — a long generation holds the connection open until it returns. `default_timeout` only caps the outbound AI call; it does **not** extend the request budget around it. To avoid `max_execution_time` / gateway `504`s on slow generations, tune all three layers together:
>
> - **PHP** — `max_execution_time` (and `request_terminate_timeout` for php-fpm)
> - **Web server** — e.g. nginx `fastcgi_read_timeout`, Apache `Timeout`
> - **Solaris** — `->timeout()` / `default_timeout` (keep it ≤ the two above so the AI call fails with a clean Solaris notification before the server kills the request)
>
> Non-blocking streaming and queued/async execution are on the roadmap — see [`missing-features.md`](../specs/missing-features.md) #3 (streaming) and #7 (queued execution).

## Tools

Pass tools to the underlying `laravel/ai` agent for this action. Tools are provider-specific — refer to your provider's laravel/ai documentation for available tool classes.

```php
use OpenAI\Laravel\Facades\OpenAI;

AiFormAction::make('research')
    ->sourceFields(['query'])
    ->targetField('result')
    ->tools([new WebSearchTool()])

// Closure form
AiFormAction::make('research')
    ->tools(fn () => auth()->user()->can('web-search') ? [new WebSearchTool()] : [])
```

## Generation Options

Tune the underlying text generation. All four options are optional — when not set, `laravel/ai` falls back to the agent's PHP attributes (`#[Temperature]`, `#[MaxTokens]`, `#[MaxSteps]`, `#[TopP]`) and then to the provider's own defaults.

```php
AiFormAction::make('summarize')
    ->temperature(0.7)   // sampling temperature (float)
    ->maxTokens(2048)    // hard cap on output tokens
    ->maxSteps(5)        // max tool-call steps in an agent loop
    ->topP(0.95)         // nucleus sampling
```

Setters accept `Closure` for runtime values (user preferences, feature flags, per-record tuning):

```php
AiFormAction::make('summarize')
    ->temperature(fn () => auth()->user()->ai_creativity)
    ->maxTokens(fn () => $this->record->is_premium ? 4096 : 1024)
```

On a preset:

```php
->preset(SummaryPreset::make()->temperature(0.3)->maxTokens(512))
```

Resolution chain per option (highest wins): action → preset → config `preset_providers[class]` → config `default_*` → `laravel/ai` default. See [Configuration](configuration.md) for the package-wide `default_temperature` / `default_max_tokens` / `default_max_steps` / `default_top_p` keys.

## Closure Support

Most setters accept a `Closure` alongside their static type, following Filament's own pattern. There are two resolution tiers.

**Action setters** resolve through Filament's `$this->evaluate()`, so their closures receive Filament's dependency injection — `$record`, `$livewire`, `$component`, `$get`, `$operation`, and the rest:

```php
AiFormAction::make('summarize')
    ->sourceFields(fn ($record) => $record->translatable_columns)
    ->targetField('summary')
    ->prompt(fn ($record, $sourceData) =>
        "Summarise '{$sourceData['body']}' for a {$record->audience} audience.");
```

- `prompt()` accepts a closure returning a `string` or a Blade `View`, and is additionally injected with the pipeline context — `$sourceData`, `$userInput`, `$locale`.
- `sourceFields()` / `targetFields()` closures run *before* the AI call (to know which fields to read/write), so they receive Filament's defaults only — `$sourceData` is not available there.
- Injected `$record` is **`null` on Create pages** (no model exists yet); closures must tolerate a null record.

Action setters with injection: `prompt()`, `sourceFields()`, `targetFields()`, `locale()`, `provider()`, `timeout()`, `tools()`, `temperature()`, `maxTokens()`, `maxSteps()`, `topP()`, `attachmentField()`, `attachmentFromUserInput()`, `attachments()`.

**Preset setters** (`maxWords()`, `tone()`, `language()`, `style()`, …) and `userInput()` resolve via Laravel's `value()` — their closures take no injected arguments (a closure defined inside your component still closes over `$this`):

```php
->preset(TranslationPreset::make()->language(fn () => $this->record->target_language))
```

Static values continue to work unchanged everywhere.

## Attachments

Send files to the underlying `laravel/ai` agent alongside the prompt — images for vision-capable models, PDFs/text for document understanding, audio for multimodal voice models. Three explicit channels feed into the same attachment slot, all of which accumulate:

```php
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;

AiFormAction::make('analyse')
    ->sourceFields(['title'])
    ->targetField('analysis')
    ->prompt('Analyse this content.')

    // Channel 1: a FileUpload field on the parent form
    ->attachmentField('reference_image')

    // Channel 2: a FileUpload inside the UserInput modal
    ->userInput(UserInput::make()->fields([
        FileUpload::make('extra_doc'),
    ]))
    ->attachmentFromUserInput('extra_doc')

    // Channel 3: hardcoded / programmatic — pass anything reasonable
    ->attachments(Image::fromUrl('https://example.com/logo.png'))   // single Files\File
    ->attachments(Audio::fromPath('intro.mp3'))                       // any Files\* type
    ->attachments($request->file('upload'))                           // Laravel UploadedFile, auto-converted
    ->attachments([Image::fromUrl('...'), $request->file('extra')])  // mixed array
    ->attachments(fn ($livewire) => Image::fromStorage(               // Closure (Filament-style)
        $livewire->record->logo_path, 'public',
    ))
```

Type detection is automatic: MIME-sniffed first, extension fallback. Image MIMEs / extensions (jpg, png, webp, heic, …) become `Files\Image`; audio MIMEs / extensions (mp3, wav, m4a, …) become `Files\Audio`; everything else becomes `Files\Document` (PDFs, text, anything unknown).

`attachmentField()` and `attachmentFromUserInput()` accept a single field name, an array, or a Closure resolving to either. `attachments()` accepts a single `Files\File`, a single `UploadedFile` (auto-converted via the same MIME detection), an array mixing both, or a Closure returning any of the above — multiple `attachments()` calls accumulate. Multiple uploads via Filament's `->multiple()` modifier flow through unchanged. Persisted paths fall back to the action's resolved storage disk (image-gen reuses `->disk()`).

Provider behaviour for attachments is delegated entirely to `laravel/ai` — providers that don't accept the file type silently drop it.

**Spatie media-library**: when `filament/spatie-laravel-media-library-plugin` is installed, `SpatieMediaLibraryFileUpload` fields are auto-detected — `->attachmentField('reference_image')` resolves both fresh uploads and existing media records (looked up by UUID through the package's own `Media` model) without any extra wiring.

## Preview

Show the AI result in a preview modal before applying it to the form, so the user can review and accept (or cancel):

```php
AiFormAction::make('summarize')
    ->sourceFields(['title', 'body'])
    ->targetField('summary')
    ->prompt('Summarise the body.')
    ->withPreview();
```

**Required: `InteractsWithSolarisPreview` trait on the owning Livewire component.**

The trait provides the `solarisPreviewData` public property the modal reads (locked: only the server writes it, so the browser cannot alter what Accept writes) and `solarisPreviewSelected` for the field checkboxes, plus the `acceptSolarisPreview()` and `refineSolaris()` Livewire methods the modal's Accept / chat-refine buttons dispatch to. Add it to whatever Livewire component hosts the form — typically a Filament Resource Page, a custom Page, or a Livewire component:

```php
use Filament\Resources\Pages\EditRecord;
use Statikbe\FilamentSolaris\Concerns\InteractsWithSolarisPreview;

class EditPost extends EditRecord
{
    use InteractsWithSolarisPreview;

    // ... your form, mount, etc.
}
```

For a plain Livewire component:

```php
use Livewire\Component;
use Statikbe\FilamentSolaris\Concerns\InteractsWithSolarisPreview;

class GenerateArticle extends Component
{
    use InteractsWithSolarisPreview;

    // ... form, mount, render, etc.
}
```

**Fail-loud check.** If `->withPreview()` (or `->conversational()`, which implies it) is configured but the trait is missing, the action throws a `RuntimeException` on first invocation with an actionable message — no silent no-op, no wasted AI tokens. The check fires after configuration validation and before the AI call.

### Selectable fields

Let the user tick which generated fields are written. `withSelectablePreview()` turns the
preview on by itself — no separate `withPreview()` needed:

```php
AiFormAction::make('analyze')
    ->sourceFields(['title', 'body'])
    ->targetFields(['summary', 'category', 'tags'])
    ->prompt('Analyze the article.')
    ->withSelectablePreview();
```

Every field starts ticked. Accept writes only the ticked ones; an unticked field is simply
not written, and is not reported as a failure.

Limit selection to some fields by passing them; the others are shown without a checkbox and
always written — useful for fields that belong together:

```php
->withSelectablePreview(['summary', 'tags'])   // category is always written
```

It combines with conversational refinement. A field the user unticked stays unticked when
the model refines; a field the refinement fills for the first time starts ticked.

`withSelectablePreview(false)` turns selection off and leaves the preview as configured. A
field name that is not a target field throws a `RuntimeException` before the AI call.

## Per-field Metadata

Ask the model for extra information beside each target field's value — where the value
came from, how sure it is, why it chose it. Solaris carries that metadata to the preview
modal and to your own code; it never stores it.

```php
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

AiFormAction::make('analyze')
    ->sourceFields(['title', 'body'])
    ->targetFields(['summary', 'category'])
    ->prompt('Analyze the article.')
    ->withPreview()
    ->fieldMetadata(fn (JsonSchemaTypeFactory $s) => [
        'evidence'  => $s->string()->enum(['verbatim', 'derived'])->required(),
        'reasoning' => $s->string()->required(),
    ])
    ->fieldMetadataInstructions(
        'evidence: "verbatim" when the value is traceable to an explicit statement in the '
        .'source, otherwise "derived". reasoning: one sentence on why.'
    );
```

The model now answers each field with an object — `{"value": "…", "evidence": "derived",
"reasoning": "…"}` — and Solaris unwraps it: the value goes into the form (through your
sanitizers, as usual), the rest becomes that field's metadata. A field whose answer is not a
well-formed object counts as a failed field. A key named `value` is not allowed: the action
throws before any AI call.

> **Mark every metadata key `->required()`.** Strict structured-output providers (OpenAI)
> reject a schema whose object properties are not all required. Solaris already makes the
> envelope and its `value` required; your own keys are up to you. For an optional piece of
> information, keep the key required and make it `->nullable()`.

The instructions explain your keys to the model. Without them it only has the key names and
any `->description()` you put on the schema.

In the preview, a field with metadata gets an info icon beside its label; hovering it shows
the metadata.

### Reusable metadata presets

Define a metadata vocabulary once and use it on any action by extending `FieldMetadata`:

```php
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Statikbe\FilamentSolaris\Support\Metadata\FieldMetadata;

class AuditTrailMetadata extends FieldMetadata
{
    public function keys(JsonSchemaTypeFactory $schema): array
    {
        return [
            'evidence'  => $schema->string()->enum(['verbatim', 'derived'])->required(),
            'reasoning' => $schema->string()->required(),
        ];
    }

    public function instructions(): ?string
    {
        return 'evidence: "verbatim" when …';
    }
}

AiFormAction::make('analyze')
    // ...
    ->fieldMetadata(AuditTrailMetadata::make());
```

Keep presets stateless — methods, no changing properties — so one instance is safe to reuse.
`->fieldMetadataInstructions()` overrides a preset's `instructions()` for one action while
keeping its keys.

### Evidence and confidence presets

Two presets ship with Solaris, so the common cases need no schema of your own:

```php
use Statikbe\FilamentSolaris\Support\Metadata\Presets\ConfidenceMetadata;
use Statikbe\FilamentSolaris\Support\Metadata\Presets\EvidenceMetadata;

->fieldMetadata(EvidenceMetadata::make())                    // ships the Evidence enum
->fieldMetadata(EvidenceMetadata::make(SourceQuality::class)) // your enum is the vocabulary
->fieldMetadata(ConfidenceMetadata::make())                  // ships the ConfidenceLevel enum
->fieldMetadata(ConfidenceMetadata::make(MyLevel::class))    // your enum, your cutoffs
->fieldMetadata(ConfidenceMetadata::make(reasoning: true))   // adds a `reasoning` key
```

- **Evidence** asks for a key `evidence` restricted to the enum's values. `Evidence` has
  `Verbatim` (traceable to an explicit statement in the source) and `Derived` (inferred,
  summarised or reformatted).
- **Confidence** asks for a `confidence` score from 0 to 1 and buckets it into the cases of a
  `ConfidenceBand` enum. `ConfidenceLevel` has `High` (0.8 and up), `Medium` (0.5) and
  `Low` (0.0). A score outside 0..1 is clamped, and the schema carries no `minimum`/`maximum`,
  as strict providers reject them.
- The evidence prompt tells the model to return null for a value it cannot support from the source. Such a field counts as failed (failure notification) rather than being filled with a guess.
- `reasoning: true` adds a required `reasoning` key (one sentence) next to the value.
- The preset writes the prompt instructions from each case's `promptDescription()`. These are
  prompt language and stay English. `->fieldMetadataInstructions()` still overrides them.

A custom vocabulary is a backed enum implementing `MetadataVocabulary`
(`promptDescription()` and `startsSelected()`); for confidence, implement `ConfidenceBand`,
which adds `threshold()` (the lowest score in the band, 0..1). Implement Filament's `HasLabel`
and `HasColor` as well and the badge uses them; otherwise it shows the case name in gray.

```php
enum SourceQuality: string implements HasColor, HasLabel, MetadataVocabulary
{
    case Official = 'official';
    case Rumour = 'rumour';

    public function promptDescription(): string { /* … */ }

    public function startsSelected(): bool
    {
        return $this === self::Official;
    }
    // getLabel(), getColor() …
}
```

There are no `withEvidence()` / `withConfidence()` shortcuts: `->fieldMetadata(...)` is the one
way in.

### Badges

With a preset, each field in the preview shows a colored badge beside its label (for example
"Derived" or "High"), with an info icon whose tooltip shows the metadata. A custom `FieldMetadata` can return its
own badge from `badge(array $metadata): ?Component` (any Filament schema component, such as
`Text::make(...)->badge()` or an `Icon`; `null` for none).

To change the badge for one action, whatever the metadata:

```php
->fieldMetadataBadge(fn (array $metadata, string $fieldName, mixed $value) =>
    Icon::make(Heroicon::ShieldCheck)->color('success')->tooltip('From source'))
```

`->fieldMetadataBadge(fn () => null)` hides badges. Passing `null` itself restores the
metadata's own `badge()`. Notes:

- `$value` is the form value as stored in the preview (after transformation), not the raw
  model output. `$metadata` is the field's raw metadata: strings and numbers, no enum instances.
- Return a fresh component on every call. The renderer attaches its own schema container to
  what you return, so do not reuse one instance.
- The badge component renders in a minimal schema without a record, so `$record` injected into
  the badge component itself is null. Use the injections of the closure instead (`$metadata`,
  `$fieldName`, `$value`, plus the action's usual ones such as `$record`).

### Which fields start ticked

With `->withSelectablePreview()`, the preview ticks fields from their metadata: a field starts
ticked when its metadata case says so (`startsSelected()` on the enum case). With the shipped
enums, `Verbatim` and `High` start ticked; `Derived`, `Medium` and `Low` do not. A field with no
metadata, or metadata matching no case, starts ticked. A custom `FieldMetadata` decides through
`startsSelected(array $metadata): bool`. Ticks are only a starting point: the user can change
them, and after a refinement round the starting ticks are recomputed only for fields whose
value changed.

### Choosing metadata at runtime

`fieldMetadata()` and `fieldMetadataInstructions()` accept closures, like `prompt()`:

```php
->fieldMetadata(fn (Article $record) => $record->is_imported
    ? EvidenceMetadata::make()
    : ConfidenceMetadata::make(reasoning: auth()->user()->isEditor()))
->fieldMetadataInstructions(fn (string $locale) => __('ai.evidence_rules', locale: $locale))
```

The `fieldMetadata()` closure may return keys (the closure's parameter must be type-hinted
`JsonSchemaTypeFactory`; the name does not matter, injection is by type), a
`FieldMetadata`, or `null` for no metadata. Anything else throws.

> **Return the same answer throughout one run.** The closure is evaluated several times per
> run: for the prompt and schema, when rendering the preview and on refinement (Accept uses the metadata stored in the preview). It is
> not cached, so a closure whose result changes mid-run (time, randomness) would unwrap or badge
> against a different vocabulary than the one the model was asked for.

### Reacting to written fields

`afterFieldsWritten()` runs after the fields are written to the form — straight after the AI
call without a preview, or on Accept with a preview:

```php
->afterFieldsWritten(function (array $values, array $metadata, Model $record) {
    // $values   = ['summary' => '…']                  only what was written
    // $metadata = ['summary' => ['evidence' => '…']]  [] when metadata is off
})
```

With a selectable preview it receives only the ticked fields. It is not called when nothing
was written. The usual Filament injections (`$record`, `$livewire`, …) are available.

> **Trust:** with a preview, the values and metadata come back from the browser on Accept,
> and a user can edit them before accepting — just as they can edit the form. Only target
> fields are ever written. Treat metadata as the model's claim, not as fact, and validate
> what you persist from this callback like any other user input.

## Conversational Refinement

Enable a chat interface inside the preview modal so the user can iteratively refine the AI's response with follow-up messages:

```php
AiFormAction::make('summarize')
    ->sourceFields(['title', 'body'])
    ->targetField('summary')
    ->preset(SummaryPreset::make())
    ->conversational()  // implies ->withPreview()
```

Each refinement turn re-runs the AI with the new message appended to the conversation history; the structured response replaces the preview, and the user can keep refining or accept.

**Requirements:**

1. **`laravel/ai`'s conversation tables must be migrated.** Conversational refinement leans on `RemembersConversations` from `laravel/ai`, which writes to `agent_conversations` and `agent_conversation_messages`. Publish and run those migrations alongside your app's:
   ```bash
   php artisan vendor:publish --tag=ai-migrations
   php artisan migrate
   ```
2. **An authenticated user.** Conversation memory is keyed on `auth()->user()`. Without an authenticated user, the chat UI still works visually within the open modal — the AI sees prior turns *in-memory via the modal's `messages` array* — but no row is written to the conversation tables and nothing is persisted across modal opens.

**Conversation lifetime.** Conversational mode persists the conversation **within a single open preview modal** only. When the user accepts (or cancels), the modal closes and the next click of the action starts a fresh conversation — the prior `agent_conversation_messages` rows remain in the DB but Solaris doesn't read them back. From the user's perspective the chat is ephemeral.

This is intentional for `0.1.0`. Cross-session resume (re-opening the action and seeing prior history) is a [planned feature](../specs/missing-features.md#11-cross-session-conversation-persistence) — it needs a Solaris-owned morph table to bind conversations to a record + action context, plus an upstream fix on attachment rehydration. Track that item if you need persistence across modal opens.
