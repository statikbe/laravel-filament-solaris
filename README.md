<img src="documentation/banner.png" width="800" alt="Laravel Filament Solaris">

# Laravel Filament Solaris

[![Latest Version on Packagist](https://img.shields.io/packagist/v/statikbe/laravel-filament-solaris.svg?style=flat-square)](https://packagist.org/packages/statikbe/laravel-filament-solaris)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/statikbe/laravel-filament-solaris/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/statikbe/laravel-filament-solaris/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/statikbe/laravel-filament-solaris/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/statikbe/laravel-filament-solaris/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/statikbe/laravel-filament-solaris.svg?style=flat-square)](https://packagist.org/packages/statikbe/laravel-filament-solaris)

AI actions for Filament v4 & v5 — auto-detect form fields, compose prompts, write structured AI responses back, generate images, and transcribe audio. Powered by [laravel/ai](https://github.com/laravel/ai).

> “There are no answers, only choices.” ― Stanislav Lem, Solaris

## Table of Contents

- [How It Works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
  - [Tailwind CSS](#tailwind-css)
- [Quick Start](#quick-start)
- [Architecture](#architecture)
- [AiAction API](#aiaction-api)
  - [Source Fields](#source-fields)
  - [Target Fields](#target-fields)
  - [Prompts](#prompts)
  - [Presets](#presets)
  - [User Input](#user-input)
  - [Locale](#locale)
  - [Provider & Model](#provider--model)
  - [Timeout](#timeout)
  - [Tools](#tools)
  - [Generation Options](#generation-options)
  - [Attachments](#attachments)
  - [Preview](#preview)
  - [Conversational Refinement](#conversational-refinement)
- [Component Factories](#component-factories)
  - [Supported Components](#supported-components)
  - [Custom Factories](#custom-factories)
  - [Option Matching](#option-matching)
- [Presets Reference](#presets-reference)
  - [SummaryPreset](#summarypreset)
  - [ClassificationPreset](#classificationpreset)
  - [TranslationPreset](#translationpreset)
  - [GenerationPreset](#generationpreset)
  - [Custom Presets](#custom-presets)
- [Prompt Builders](#prompt-builders)
  - [Inline Prompts](#inline-prompts)
  - [View Prompts](#view-prompts)
  - [Custom Prompt Builders](#custom-prompt-builders)
- [ImageGenerationAction](#imagegenerationaction)
  - [Basic Usage](#basic-usage-1)
  - [Size & Quality](#size--quality)
  - [Provider & Model](#provider--model-1)
  - [Storage](#storage)
  - [Reference Images](#reference-images)
  - [Testing ImageGenerationAction](#testing-imagegenerationaction)
- [DictationFieldAction](#dictationfieldaction)
  - [Pure Transcription](#pure-transcription)
  - [Transcription + AI Processing](#transcription--ai-processing)
  - [Transcription Provider](#transcription-provider)
- [Usage Tracking](#usage-tracking)
  - [Rate Limiting & Retry](#rate-limiting--retry)
- [Security Considerations](#security-considerations)
- [Testing](#testing)
- [Configuration](#configuration)
  - [Per-Panel Configuration (Plugin)](#per-panel-configuration-plugin)
- [Versioning](#versioning)
- [Changelog](#changelog)
- [License](#license)

## How It Works

`AiAction` is a Filament `Action` that reads values from source fields, sends them to an AI provider via `laravel/ai`, and writes structured responses back into target fields. The package auto-detects target component types (Select, TextInput, Toggle, etc.) and handles bidirectional data transformation — converting form state to prompt context and AI responses back to valid form state.

```mermaid
flowchart LR
    A[Source Fields] -->|read values| B[AiAction]
    G[UserInput] -->|modal form data| B
    P[Prompt] -->|extra prompt| B
    B -->|compose prompt| C[PromptBuilder]
    C -->|structured request| D[SolarisAgent]
    D -->|JSON response| E[ComponentFactory]
    E -->|transform & write| F[Target Fields]
```

## Installation

```bash
composer require statikbe/laravel-filament-solaris
```

Publish the config file:

```bash
php artisan vendor:publish --tag="filament-solaris-config"
```

Optionally publish the views (prompt templates) and translations:

```bash
php artisan vendor:publish --tag="filament-solaris-views"
php artisan vendor:publish --tag="filament-solaris-translations"
```

### Tailwind CSS

Solaris ships Blade views (preview modal, conversational refinement modal, dictation modal, loading state, etc.) that use Tailwind utility classes. Without telling Tailwind to scan the package's views, those classes are purged and the modals render unstyled.

Add a `@source` directive to your [Filament theme CSS](https://filamentphp.com/docs/4.x/styling/overview):

```css
/* resources/css/filament/admin/theme.css */
@source "../../vendor/statikbe/laravel-filament-solaris/resources/views";
```

Then rebuild your Filament theme (`npm run build` or `php artisan filament:assets`). This is required for every consumer — not optional and not specific to any single action.

## Quick Start

Add an `AiAction` to a Filament form. This example reads `title` and `body`, then writes a summary into the `summary` field:

```php
use Statikbe\FilamentSolaris\Actions\AiAction;
use Statikbe\FilamentSolaris\Prompts\Presets\SummaryPreset;

Forms\Components\Actions::make([
    AiAction::make('summarize')
        ->sourceFields(['title', 'body'])
        ->targetField('summary')
        ->preset(SummaryPreset::make()->maxWords(100)),
]),
```

Classify content into a Select field:

```php
use Statikbe\FilamentSolaris\Prompts\Presets\ClassificationPreset;

AiAction::make('classify')
    ->sourceFields(['title', 'body'])
    ->targetField('category_id')
    ->preset(ClassificationPreset::make()->context('tech blog'))
```

Use a plain prompt string:

```php
AiAction::make('generate-slug')
    ->sourceFields(['title'])
    ->targetField('slug')
    ->prompt('Generate a URL-friendly slug from the title. Lowercase, hyphens only, no special characters.')
```

## Architecture

### Execution Pipeline

```mermaid
sequenceDiagram
    participant User
    participant AiAction
    participant PromptBuilder
    participant SolarisAgent
    participant AI as laravel/ai Provider
    participant Factory as ComponentFactory

    User->>AiAction: clicks action button
    Note over AiAction: if UserInput configured
    AiAction->>User: show modal form
    User->>AiAction: submit user input

    AiAction->>AiAction: validate config
    AiAction->>AiAction: collect source field values
    AiAction->>AiAction: resolve target factories

    AiAction->>PromptBuilder: build(instruction, sourceData, factories, record, locale, userInput)
    PromptBuilder-->>AiAction: composed prompt string

    AiAction->>SolarisAgent: configure(prompt, factories)
    SolarisAgent->>AI: prompt() with JSON schema
    AI-->>SolarisAgent: structured JSON response

    loop for each target field
        AiAction->>Factory: toFormValue(aiValue)
        Factory-->>AiAction: transformed value
        AiAction->>AiAction: $set(field, value)
    end

    AiAction->>User: success/partial/error notification
```

### Component Hierarchy

```mermaid
classDiagram
    class ComponentFactory {
        <<interface>>
        +responseSchema(JsonSchema): Type
        +toFormValue(mixed): mixed
        +toPromptContext(mixed): mixed
    }

    class AbstractComponentFactory {
        <<abstract>>
        #component: Component
        #scope: Closure|null
        +make(Component, Closure|null): static
    }

    ComponentFactory <|.. AbstractComponentFactory
    AbstractComponentFactory <|-- SelectFactory
    AbstractComponentFactory <|-- TextFactory
    AbstractComponentFactory <|-- BooleanFactory
    AbstractComponentFactory <|-- CheckboxListFactory
    AbstractComponentFactory <|-- RichEditorFactory
    AbstractComponentFactory <|-- FileUploadFactory
    SelectFactory <|-- RadioFactory

    class PromptBuilder {
        <<interface>>
        +build(): string
        +defaultUserInput(): UserInput|null
    }

    class AbstractPromptBuilder {
        <<abstract>>
        #buildViewData(): array
        #resolveLocaleName(): string
    }

    PromptBuilder <|.. AbstractPromptBuilder
    AbstractPromptBuilder <|-- InlinePromptBuilder
    AbstractPromptBuilder <|-- ViewPromptBuilder
    AbstractPromptBuilder <|-- Preset

    Preset <|-- SummaryPreset
    Preset <|-- ClassificationPreset
    Preset <|-- TranslationPreset
    Preset <|-- GenerationPreset
```

## AiAction API

`AiAction` extends Filament's `Action` class and adds three concerns via traits: `HasSourceFields`, `HasTargetFields`, and `HasUserInput`.

### Source Fields

Source fields are the form fields whose values are read and sent to the AI as context.

```php
AiAction::make('summarize')
    ->sourceFields(['title', 'body', 'author'])
```

Use `sourceScope()` to transform a source value before it reaches the prompt. This is useful for truncating long content or formatting values:

```php
AiAction::make('summarize')
    ->sourceFields(['title', 'body'])
    ->sourceScope('body', fn (string $value) => str($value)->limit(3000)->toString())
```

### Target Fields

Target fields are the form fields that receive the AI response. The package auto-detects the Filament component type for each target field and instantiates the appropriate `ComponentFactory`.

```php
// Single target
AiAction::make('classify')
    ->targetField('category_id')

// Multiple targets
AiAction::make('fill')
    ->targetFields(['summary', 'category_id', 'is_featured'])
```

Use `targetScope()` to constrain relationship-based options (e.g., filter a Select's relationship query):

```php
AiAction::make('classify')
    ->targetField('category_id')
    ->targetScope('category_id', fn ($query) => $query->where('active', true))
```

Use `targetHint()` to append a behavioural nudge to a specific field's JSON-schema description — the hint is sent to the AI alongside the auto-generated schema, so it shapes how the field gets filled without rewriting the whole prompt:

```php
AiAction::make('fill')
    ->targetFields(['summary', 'tags'])
    ->prompt('Read the body and fill the fields.')
    ->targetHint('summary', 'Keep it under 80 words. No marketing language.')
    ->targetHint('tags', 'Pick 3–5 short, lowercase, hyphen-separated tags.');
```

Hints compose with the structural description the factory generates from the component (e.g. for a `Select`, the enum of allowed values). They're particularly useful for narrowing tone, length, or formatting per field without bloating the main prompt.

### Prompts

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

### Presets

Presets are reusable prompt builders for common AI tasks. Each preset renders its own Blade template with configurable parameters. See [Presets Reference](#presets-reference) for the full API.

### User Input

User input opens a modal before the AI executes, allowing the end user to provide additional instructions or make selections. The user's input is included in the prompt.

```php
use Statikbe\FilamentSolaris\Support\UserInput;

AiAction::make('generate')
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
AiAction::make('translate')
    ->sourceFields(['body'])
    ->targetField('body_nl')
    ->preset(TranslationPreset::make()->language('nl'))
    ->withDefaultUserInput()
```

### Locale

By default the application locale (`app()->getLocale()`) is used as a hint in the prompt. Override per-action:

```php
->locale('nl')
```

### Provider & Model

Override which AI provider and model are used per-action. When not set, the package falls through a resolution chain: action → preset → config `preset_providers` → config `default_provider` → `laravel/ai` default. See [Configuration](documentation/configuration.md) for details.

```php
// Single provider + model
AiAction::make('summarize')
    ->provider('anthropic', 'claude-sonnet-4-5-20250514')

// Failover array — tries providers in order on failure
AiAction::make('summarize')
    ->provider(['openai' => 'gpt-4o', 'anthropic'])

// Closure (Filament convention)
AiAction::make('classify')
    ->provider(fn () => config('my-app.ai_provider'))

// On a preset
->preset(SummaryPreset::make()->provider('openai', 'gpt-4o-mini'))
```

### Timeout

Set the HTTP timeout in seconds for the AI call. Defaults to the `laravel/ai` default (60s) when not configured.

```php
AiAction::make('summarize')
    ->timeout(120)  // 2 minutes for long content

AiAction::make('classify')
    ->timeout(30)   // quick classification
```

A package-wide default can be set in the config (`default_timeout`). See [Configuration](documentation/configuration.md).

### Tools

Pass tools to the underlying `laravel/ai` agent for this action. Tools are provider-specific — refer to your provider's laravel/ai documentation for available tool classes.

```php
use OpenAI\Laravel\Facades\OpenAI;

AiAction::make('research')
    ->sourceFields(['query'])
    ->targetField('result')
    ->tools([new WebSearchTool()])

// Closure form
AiAction::make('research')
    ->tools(fn () => auth()->user()->can('web-search') ? [new WebSearchTool()] : [])
```

### Generation Options

Tune the underlying text generation. All four options are optional — when not set, `laravel/ai` falls back to the agent's PHP attributes (`#[Temperature]`, `#[MaxTokens]`, `#[MaxSteps]`, `#[TopP]`) and then to the provider's own defaults.

```php
AiAction::make('summarize')
    ->temperature(0.7)   // sampling temperature (float)
    ->maxTokens(2048)    // hard cap on output tokens
    ->maxSteps(5)        // max tool-call steps in an agent loop
    ->topP(0.95)         // nucleus sampling
```

Setters accept `Closure` for runtime values (user preferences, feature flags, per-record tuning):

```php
AiAction::make('summarize')
    ->temperature(fn () => auth()->user()->ai_creativity)
    ->maxTokens(fn () => $this->record->is_premium ? 4096 : 1024)
```

On a preset:

```php
->preset(SummaryPreset::make()->temperature(0.3)->maxTokens(512))
```

Resolution chain per option (highest wins): action → preset → config `preset_providers[class]` → config `default_*` → `laravel/ai` default. See [Configuration](documentation/configuration.md) for the package-wide `default_temperature` / `default_max_tokens` / `default_max_steps` / `default_top_p` keys.

### Closure Support

Most setters accept a `Closure` alongside their static type, following Filament's own pattern. The closure is resolved at execution time via Laravel's `value()` helper, enabling dynamic configuration based on the current record or application state:

```php
AiAction::make('translate')
    ->sourceFields(fn () => ['title', "body_{$sourceLocale}"])
    ->targetFields(fn () => ["body_{$targetLocale}"])
    ->locale(fn () => auth()->user()->locale)
    ->preset(
        TranslationPreset::make()
            ->language(fn () => $this->record->target_language)
    )
```

Closures are supported on: `sourceFields()`, `targetFields()`, `locale()`, `provider()`, `timeout()`, `tools()`, `temperature()`, `maxTokens()`, `maxSteps()`, `topP()`, `userInput()`, `attachmentField()`, `attachmentFromUserInput()`, `attachments()`, and all preset setters (e.g., `maxWords()`, `tone()`, `language()`, `style()`, etc.). Static values continue to work unchanged.

### Attachments

Send files to the underlying `laravel/ai` agent alongside the prompt — images for vision-capable models, PDFs/text for document understanding, audio for multimodal voice models. Three explicit channels feed into the same attachment slot, all of which accumulate:

```php
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;

AiAction::make('analyse')
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

### Preview

Show the AI result in a preview modal before applying it to the form, so the user can review and accept (or cancel):

```php
AiAction::make('summarize')
    ->sourceFields(['title', 'body'])
    ->targetField('summary')
    ->prompt('Summarise the body.')
    ->withPreview();
```

**Required: `InteractsWithSolarisPreview` trait on the owning Livewire component.**

The trait provides the `solarisPreviewData` public property the modal binds to, plus the `acceptSolarisPreview()` and `refineSolaris()` Livewire methods the modal's Accept / chat-refine buttons dispatch to. Add it to whatever Livewire component hosts the form — typically a Filament Resource Page, a custom Page, or a Livewire component:

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

### Conversational Refinement

Enable a chat interface inside the preview modal so the user can iteratively refine the AI's response with follow-up messages:

```php
AiAction::make('summarize')
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

This is intentional for `0.1.0`. Cross-session resume (re-opening the action and seeing prior history) is a [planned feature](specs/missing-features.md#11-cross-session-conversation-persistence) — it needs a Solaris-owned morph table to bind conversations to a record + action context, plus an upstream fix on attachment rehydration. Track that item if you need persistence across modal opens.

## Component Factories

Factories are the bridge between Filament components and AI. Each factory implements three methods:

| Method | Purpose |
|---|---|
| `responseSchema(JsonSchema $schema): Type` | Returns the JSON schema fragment for this field, constraining the AI's output format |
| `toFormValue(mixed $aiValue): mixed` | Transforms the AI's raw JSON response into valid Filament form state |
| `toFormValueFromFile(string $content, string $mimeType): mixed` | Transforms generated file content (e.g. from `ImageGenerationAction`) into valid form state |
| `toPromptContext(mixed $formValue): mixed` | Transforms the current form state into a human-readable string for the prompt |

### Supported Components

| Filament Component | Factory | AI Schema | Notes |
|---|---|---|---|
| `Select` | `SelectFactory` | `string` enum or free-text | Enum mode for ≤100 options, free-text with fuzzy matching for >100 |
| `Radio` | `RadioFactory` | `string` enum or free-text | Extends `SelectFactory` |
| `ToggleButtons` | `SelectFactory` | `string` enum or free-text | Same as Select, supports `multiple()` |
| `CheckboxList` | `CheckboxListFactory` | `array` of `string` | Multi-select, same matching as Select |
| `TextInput` | `TextFactory` | `string` | Respects `maxLength` if set on the component |
| `Textarea` | `TextFactory` | `string` | Same as TextInput |
| `MarkdownEditor` | `MarkdownFactory` | `string` (Markdown) | AI prompted to output Markdown syntax |
| `RichEditor` | `RichEditorFactory` | `string` (HTML) | AI returns HTML, factory converts to TipTap JSON for form state |
| `CodeEditor` | `TextFactory` | `string` | Plain text |
| `Toggle` | `BooleanFactory` | `boolean` | Handles string/int coercion ("true", "yes", 1 → true) |
| `Checkbox` | `BooleanFactory` | `boolean` | Same as Toggle |
| `TagsInput` | `TagsFactory` | `array` of `string` | Includes suggestions in schema, handles comma-separated strings |
| `FileUpload` | `FileUploadFactory` | — | Supports `toFormValueFromFile()` for image generation output |
| `SpatieMediaLibraryFileUpload` | `FileUploadFactory` | — | Same as FileUpload — creates Livewire temp upload, Spatie handles Media record on save |

### Custom Factories

Register a factory for a custom or unsupported component:

```php
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;

// In a service provider
FilamentSolaris::registerFactory(MyComponent::class, MyComponentFactory::class);
```

Or add it to the `factories` array in `config/filament-solaris.php`:

```php
'factories' => [
    // ...defaults...
    \App\Filament\Components\ColorPicker::class => \App\Factories\ColorFactory::class,
],
```

Implement the factory by extending the abstract base class:

```php
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Statikbe\FilamentSolaris\Factories\ComponentFactory;

class ColorFactory extends ComponentFactory
{
    public function responseSchema(JsonSchemaTypeFactory $schema): Type
    {
        return $schema->string()
            ->description('A hex color code, e.g. #ff5733')
            ->required();
    }

    public function toFormValue(mixed $aiValue): mixed
    {
        // Ensure the value starts with #
        return str_starts_with($aiValue, '#') ? $aiValue : "#{$aiValue}";
    }

    public function toPromptContext(mixed $formValue): mixed
    {
        return $formValue ?? 'No color selected';
    }
}
```

The factory map also supports class inheritance — if a factory is registered for a parent class, subclasses will match it automatically.

### Option Matching

`SelectFactory` and `CheckboxListFactory` use a 6-step fuzzy matching chain to resolve AI responses to valid option keys. This tolerates common AI "near-misses":

1. **Exact key match** — the AI returned the option key directly
2. **Exact label match** — the AI returned the option label
3. **Case-insensitive label** — "Technology" matches "technology"
4. **Substring** — "tech" matches "Technology & Science"
5. **Levenshtein ≤ 3** — "technolgy" matches "technology"
6. **Fallback** — return the raw value

When a Select/CheckboxList has more than `max_options` (default: 100) options, the schema switches from a strict enum to free-text with a sample of 10 options and relies on fuzzy matching to resolve the response.

## Presets Reference

### SummaryPreset

Generates a summary of the source content.

```php
SummaryPreset::make()
    ->maxWords(200)       // default: 200
    ->tone('professional') // default: config('filament-solaris.default_tone')
    ->language('French')   // overrides locale for output language
```

### ClassificationPreset

Classifies content into the target field's options.

```php
ClassificationPreset::make()
    ->allowMultiple()           // allow selecting multiple categories (for CheckboxList targets)
    ->context('tech blog')      // additional context about the classification domain
```

### TranslationPreset

Translates source content into a target language.

```php
TranslationPreset::make()
    ->language('fr')                // required — target language
    ->preserveFormatting()          // default: true — preserve HTML/Markdown structure
    ->glossary('API = API (never translate), Laravel = Laravel')
```

The `TranslationPreset` defines a `defaultUserInput()` that renders a language selector populated from `supported_locales`. Use `->withDefaultUserInput()` on the action to enable it.

### GenerationPreset

Generates new content based on source data and user instructions.

```php
GenerationPreset::make()
    ->tone('casual')
    ->style('blog post')
    ->audience('developers')
    ->maxLength(500)
```

Defines a `defaultUserInput()` with a "What would you like to generate?" textarea.

### Custom Presets

Extend the `Preset` base class to create reusable prompt patterns:

```php
use Statikbe\FilamentSolaris\Prompts\Presets\Preset;
use Statikbe\FilamentSolaris\Support\UserInput;

class SeoPreset extends Preset
{
    protected ?string $keyword = null;

    public function keyword(string $keyword): static
    {
        $this->keyword = $keyword;
        return $this;
    }

    protected function promptView(): string
    {
        // A Blade view in your application
        return 'prompts.seo';
    }

    protected function viewData(): array
    {
        return [
            'keyword' => $this->keyword,
        ];
    }

    // Optional: provide a default user input modal
    public function defaultUserInput(): ?UserInput
    {
        return UserInput::make()
            ->prompt('Target keyword')
            ->placeholder('Enter the primary SEO keyword');
    }
}
```

The Blade view receives all standard prompt variables (`$sourceData`, `$factories`, `$responseSchema`, `$record`, `$locale`, `$localeName`, `$userInput`) plus the preset's `viewData()`.

## Prompt Builders

The `PromptBuilder` interface has one method:

```php
public function build(
    string|View $instruction,
    array $sourceData,
    array $factories,
    ?Model $record = null,
    ?string $locale = null,
    array $userInput = [],
): string;
```

### Inline Prompts

Used when you call `->prompt('...')` with a string. Renders the `base-wrapper.blade.php` template which includes:
- A system preamble
- Your instruction
- User input section (if present)
- Locale hint (for non-English locales)
- Source data formatted as key-value pairs
- JSON response schema block

### View Prompts

Used when you call `->prompt(view('...'))`. The provided Blade view is rendered with all standard variables. You control the full prompt structure.

### Custom Prompt Builders

Implement `PromptBuilder` directly for full control:

```php
use Statikbe\FilamentSolaris\Contracts\PromptBuilder;

class MyPromptBuilder implements PromptBuilder
{
    public function build(
        string|View $instruction,
        array $sourceData,
        array $factories,
        ?Model $record = null,
        ?string $locale = null,
        array $userInput = [],
    ): string {
        // Build the prompt however you want
        return "...";
    }

    public function defaultUserInput(): ?UserInput
    {
        return null;
    }
}
```

Use it on an action:

```php
AiAction::make('custom')
    ->sourceFields(['title'])
    ->targetField('body')
    ->promptBuilder(new MyPromptBuilder())
```

## ImageGenerationAction

`ImageGenerationAction` generates images via `laravel/ai`'s Image API and writes them to `FileUpload` or `SpatieMediaLibraryFileUpload` fields. It composes a text prompt from an instruction, source field values, and optional user input.

### Basic Usage

```php
use Statikbe\FilamentSolaris\Actions\ImageGenerationAction;

Forms\Components\Actions::make([
    ImageGenerationAction::make('generate-poster')
        ->prompt('Generate a movie poster based on the story')
        ->sourceFields(['title', 'description'])
        ->targetField('poster'),
]),

SpatieMediaLibraryFileUpload::make('poster')
    ->collection('poster')
    ->disk('public')
    ->image(),
```

### Size & Quality

Control the image dimensions and quality using enums or strings:

```php
use Statikbe\FilamentSolaris\Enums\ImageSize;
use Statikbe\FilamentSolaris\Enums\ImageQuality;

ImageGenerationAction::make('generate')
    ->prompt('A hero banner image')
    ->targetField('hero_image')
    ->imageSize(ImageSize::Landscape)       // or 'landscape', '3:2'
    ->imageQuality(ImageQuality::High)      // or 'high'
```

Available sizes: `Square` (`1:1`), `Portrait` (`2:3`), `Landscape` (`3:2`) — or pass any ratio string directly.

Available qualities: `Low`, `Medium`, `High`.

### Provider & Model

Image generation has its own provider resolution chain, separate from the structured output pipeline:

```php
ImageGenerationAction::make('generate')
    ->prompt('A product photo')
    ->targetField('image')
    ->provider('openai', 'gpt-image-1.5')
    ->timeout(120)
```

**Resolution chain** (highest wins):
1. Action-level `->provider()`
2. Config `default_image_provider` / `default_image_model`
3. laravel/ai default (`config('ai.default_for_images')`)

Supported image providers in laravel/ai: **OpenAI**, **Gemini**, **xAI (Grok)**.

### Storage

For `FileUpload` and `SpatieMediaLibraryFileUpload` targets, the generated image is stored as a Livewire temporary upload. Filament's save pipeline handles the rest — including creating Spatie Media records. The image is stored to the disk/directory configured on the component itself.

For other component types (e.g. `TextInput`), the image is stored to the disk/directory from the package config and the path is set as the field value:

```php
// In config/filament-solaris.php
'default_image_disk' => null,           // null = default filesystem disk
'default_image_directory' => 'ai-images',
'default_image_visibility' => null,     // 'public' or null
```

### User Input

Add a modal for the user to provide additional instructions:

```php
use Statikbe\FilamentSolaris\Support\UserInput;

ImageGenerationAction::make('generate')
    ->prompt('Generate an image based on the product')
    ->sourceFields(['name', 'description'])
    ->targetField('image')
    ->userInput(UserInput::make()
        ->prompt('Any specific instructions?')
        ->placeholder('e.g. bright colors, minimalist style...')
    )
```

### Reference Images

Pass an input image (or several) to the image-generation provider for image-to-image, edit, or reference-based generation. OpenAI switches to its `images/edits` endpoint; Gemini sends the input as native multi-modal parts. Same three-channel API as [`AiAction` Attachments](#attachments) — only `Files\Image` instances reach the gateway (other types are silently dropped):

```php
use Laravel\Ai\Files\Image;

ImageGenerationAction::make('reskin')
    ->prompt('Reskin in studio-photography style')
    ->targetField('featured_image')
    ->attachmentField('reference_image')   // FileUpload on the parent form
```

Closure form (e.g. for a brand logo from a record relationship):

```php
->attachments(fn ($livewire) => [
    Image::fromStorage($livewire->record->logo_path, 'public'),
])
```

Multiple references work too — bind a `->multiple()` FileUpload, or supply an array from a closure. Conversational refinement re-attaches the input image on every turn, so "now make it warmer" follow-ups still see the original reference.

### Testing ImageGenerationAction

See [Testing documentation](documentation/testing.md#testing-imagegenerationaction).

## DictationFieldAction

`DictationFieldAction` captures audio via the browser's MediaRecorder API, transcribes it using `laravel/ai`'s Transcription API, and optionally processes the transcript through the AI pipeline. It works in two modes: pure transcription and transcription with AI processing.

Attach it directly to any Filament `Field` via `->hintAction(...)` (works on `Textarea`, `RichEditor`, etc.) or via `->suffixAction(...)` on a `TextInput`. The transcript is written back into the **host field** — no `->targetField()` call needed.

### Pure Transcription

```php
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Statikbe\FilamentSolaris\Actions\DictationFieldAction;

// On a TextInput (suffix or hint both work)
TextInput::make('title')
    ->suffixAction(
        DictationFieldAction::make()->lang('en')
    );

// On a Textarea / RichEditor via hintAction
Textarea::make('notes')
    ->hintAction(
        DictationFieldAction::make()
            ->lang('en')
            ->append()     // append to existing content instead of replacing
    );
```

> **Language tag format is provider-specific.** Most transcription APIs (OpenAI Whisper, Mistral, etc.) want ISO 639-1 alpha-2 (`'en'`, `'nl'`, `'fr'`). Google Speech and Azure Speech accept BCP 47 with a region tag (`'nl-BE'`). Passing `'nl-BE'` to Whisper or Mistral returns a 422 "Invalid language alpha2 code" — strip the region for those.

### Transcription + AI Processing

Add a `->prompt()` or `->preset()` and the transcript flows through the AI pipeline before being written:

```php
Textarea::make('summary')
    ->hintAction(
        DictationFieldAction::make('voice-summary')
            ->preset(SummaryPreset::make()->maxWords(200))
            ->locale('nl')
            ->lang('nl')
    );
```

To write into multiple fields (or a different field than the host), set `->targetFields()` explicitly:

```php
Textarea::make('summary')
    ->hintAction(
        DictationFieldAction::make('voice-classify')
            ->targetFields(['summary', 'category_id'])
            ->prompt('Summarize the transcription and classify it.')
    );
```

The transcript becomes the source data (`['transcription' => $transcript]`) for any prompt or preset.

### Transcription Provider

The transcription step and AI processing step can use different providers:

```php
DictationFieldAction::make('voice')
    ->preset(SummaryPreset::make())
    ->transcriptionProvider('openai', 'whisper-1')         // transcription
    ->transcriptionTimeout(30)                              // transcription timeout
    ->provider('anthropic', 'claude-sonnet-4-5-20250514')  // AI processing
    ->timeout(120)                                          // AI processing timeout
```

Package-wide defaults can be set in the config (`default_transcription_provider`, `default_transcription_model`, `default_transcription_timeout`). See [Configuration](documentation/configuration.md).

### How It Works

When the user clicks the dictation button, a modal opens with a recording UI. The user clicks to start recording, speaks, then clicks to stop. The audio is uploaded and transcribed server-side. In AI processing mode, the transcript is then fed into the prompt pipeline.

The recording UI shows clear visual states: a pulsing red button with elapsed timer while recording, a green checkmark when the upload completes, and inline error messages for microphone permission issues.

### Browser Support

DictationFieldAction uses the MediaRecorder API and works in all modern browsers (Chrome 49+, Edge 79+, Firefox 25+, Safari 14.1+). The button is automatically hidden in unsupported browsers.

### Testing DictationFieldAction

See [Testing documentation](documentation/testing.md#testing-dictationfieldaction).

## Usage Tracking

Every Solaris AI call dispatches one of two events you can listen to for metering, budgeting, or audit purposes:

- **`Statikbe\FilamentSolaris\Events\SolarisResponseReceived`** — fired after each successful call (text, image, or transcription). Carries the laravel/ai `Usage` object (prompt/completion/cache/reasoning token counts) plus Solaris-specific context: action name, action class, resolved provider/model, call duration, the authenticated user (if any), and the Livewire component that owns the form.
- **`Statikbe\FilamentSolaris\Events\SolarisResponseFailed`** — fired when an `AiException` is caught (rate limit, provider outage, configuration error). Same shape, but carries the exception instead of usage.

Solaris does **not** persist anything — apps decide what to do with the data. A typical listener:

```php
use Statikbe\FilamentSolaris\Events\SolarisResponseReceived;

class TrackAiUsage
{
    public function handle(SolarisResponseReceived $event): void
    {
        AiCall::create([
            'action_name'             => $event->actionName,
            'action_class'            => $event->actionClass,
            'user_id'                 => $event->user?->getAuthIdentifier(),
            'provider'                => is_array($event->provider)
                ? ($event->provider[0] ?? null)
                : (is_object($event->provider) ? $event->provider->value : $event->provider),
            'model'                   => $event->model,
            'prompt_tokens'           => $event->usage->promptTokens,
            'completion_tokens'       => $event->usage->completionTokens,
            'cache_read_input_tokens' => $event->usage->cacheReadInputTokens,
            'cache_write_input_tokens'=> $event->usage->cacheWriteInputTokens,
            'reasoning_tokens'        => $event->usage->reasoningTokens,
            'duration_ms'             => $event->durationMs,
            'created_at'              => now(),
        ]);
    }
}
```

Register it in `EventServiceProvider` the usual Laravel way. The event also fires from the Solaris fakes (`AiAction::fake()`, `ImageGenerationAction::fake()`, `DictationFieldAction::fake()`) so you can test your listener without hitting a real provider — the fake dispatches with a zero-token `Usage`.

**What the event deliberately does NOT carry:** the prompt text, the source field values, or the AI response. These can be large, may contain PII, and would bloat any listener's storage. Use `SolarisPromptLogger` (gate it on `prompt_logging_enabled`) if you need that level of detail for development.

**Versioning note:** these events ship in `0.1.0`. Apps that adopt Solaris on `0.1.x` and don't register a listener can opt into tracking later by registering one — but only future calls will be captured. Plan accordingly.

### Rate Limiting & Retry

Solaris distinguishes three failure modes when an `AiException` is caught and renders dedicated user-facing notifications for each, per pipeline:

| Exception | Text (`AiAction`) | Image (`ImageGenerationAction`) | Transcription (`DictationFieldAction`) |
|---|---|---|---|
| `RateLimitedException` | `notifications.rate_limited` | `notifications.image_generation_rate_limited` | `notifications.transcription_rate_limited` |
| `ProviderOverloadedException` | `notifications.overloaded` | `notifications.overloaded` | `notifications.transcription_overloaded` |
| any other `AiException` | `notifications.error` | `notifications.image_generation_error` | `notifications.transcription_error` |

Translations ship for English, Dutch, and French; override any of these keys in your app's published `lang/vendor/filament-solaris/{locale}/filament-solaris.php` to customise the wording.

**Backoff / retry hook.** The `SolarisResponseFailed` event documented above carries the raw exception — listen for it and `instanceof RateLimitedException` for app-level backoff or queued retry:

```php
use Laravel\Ai\Exceptions\RateLimitedException;
use Statikbe\FilamentSolaris\Events\SolarisResponseFailed;

class HandleAiRateLimit
{
    public function handle(SolarisResponseFailed $event): void
    {
        if (! $event->exception instanceof RateLimitedException) {
            return;
        }

        // Examples: enqueue a delayed retry, increment a per-user Redis
        // counter, page ops, fall back to a cached previous result …
        RetrySolarisAction::dispatch(
            actionName: $event->actionName,
            user: $event->user,
        )->delay(now()->addSeconds(30));
    }
}
```

Solaris itself does not retry automatically — that decision (when to retry, with what backoff, after how many attempts to give up) is yours to make.

## Security Considerations

Solaris glues two untrusted boundaries together: **user-typed values flow into the prompt**, and **AI-generated output flows back into form fields.** Both deserve explicit handling — particularly when the form is public-facing, or when AI-populated values are later displayed to *other* users.

### Treat AI output as untrusted user input

Anything the AI writes back is, for security purposes, user-generated content. The user typing source values can steer what comes out — current LLMs are not reliably resistant to prompt-injection, and no input-side mitigation Solaris ships will change that. The real defense lives at the **render layer**, in the templates that display AI-populated values.

**Always-safe rendering** (Blade auto-escapes):

```blade
{{ $post->summary }}  {{-- safe, escaped --}}
```

**Unsafe unless you sanitize first**:

```blade
{!! $post->summary !!}  {{-- raw — only OK if you trust the source 100% --}}
```

The same rule applies to **Mail templates, PDF templates, exported JSON, webhook payloads, and any other place that's not Blade auto-escaping**. If your app routes an AI-populated value through `{!! !!}`, an HTML email body, a PDF library, an SMS, or a CSV export, that path must be sanitized at render time.

### Field-type guidance

| Filament component | AI-injection risk on form display | Notes |
|---|---|---|
| `TextInput` / `Textarea` / `MarkdownEditor` / `CodeEditor` | Low **on the form itself** (Blade escapes), high if value is rendered as raw HTML elsewhere | The form is safe; downstream rendering is your responsibility. |
| `RichEditor` | Low — Filament sanitizes RichEditor output via TipTap on render | Safer field type when AI output may contain markup. |
| `Select` / `Radio` / `CheckboxList` / `Toggle` | None | The factory constrains the AI to a fixed enum of allowed values — the output is structurally bounded. |
| `FileUpload` (image generation) | None — binary content with a verified MIME | The image is stored as a Livewire temporary upload; no text-injection vector. |

If you have a free-text field whose value will be rendered as HTML elsewhere, prefer `RichEditor` for that field, or use the sanitization hook below.

### Sanitization hook

Each `AiAction` accepts an optional sanitizer that runs on every AI value before it's written to form state:

```php
use Mews\Purifier\Facades\Purifier;  // any HTML purifier of your choice

AiAction::make('summarize')
    ->targetFields(['summary', 'body_html'])
    ->prompt('…')
    ->sanitize(fn (mixed $value) => is_string($value) ? Purifier::clean($value) : $value);
```

For different sanitization per field, use `sanitizeField()` to override per-action:

```php
AiAction::make('fill')
    ->targetFields(['plain_summary', 'rich_body'])
    ->prompt('…')
    ->sanitizeField('plain_summary', fn (mixed $value) => is_string($value) ? strip_tags($value) : $value)
    ->sanitizeField('rich_body', fn (mixed $value) => is_string($value) ? Purifier::clean($value) : $value);
```

Sanitizers run **after** the factory's `toFormValue()` but **before** the value is written. If a sanitizer throws, the value routes to "failed fields" (same as a `toFormValue()` failure) and the exception is `report()`'d so your exception tracker picks it up.

Solaris does not ship a default sanitizer because the right policy is application-specific. The hook is here so you can plug in HTML Purifier, DOMPurify-server, `strip_tags`, your CSP rules, or whatever your security review demands.

### Source-field steering

If a `sourceFields()` entry is user-controlled (TextInput, Textarea, etc.), that user can influence what the AI outputs. This is by design — letting users steer AI is often the whole point — but if your form has multiple actors (e.g. a commenter triggers an action that affects a moderator's view), think about whether the actor of the source field is the same person reading the target.

### Scoping which users can trigger actions

Use Filament's standard `Action::visible()` / `Action::authorize()` and your policy classes to gate AI actions per-user:

```php
AiAction::make('rewrite-customer-message')
    ->visible(fn () => auth()->user()->can('moderate', $record))
    ->sourceFields(['body'])
    ->targetField('body_clean');
```

Multi-panel apps can gate AI globally per panel via the [plugin's visibility predicate](#per-panel-configuration-plugin) (`FilamentSolarisPlugin::make()->visible(...)` or `->disabled()`).

### Auditing AI activity

The [Usage Tracking](#usage-tracking) events (`SolarisResponseReceived` / `SolarisResponseFailed`) carry the action name, action class, authenticated user, and Livewire component. Listen for those if you need an audit trail of who triggered which AI call when. (Deliberately doesn't carry prompts/responses — see that section for why and how to log those separately.)

## Testing

If you want to write tests, please check [the testing documentation](documentation/testing.md).

## Configuration

The configuration is published to `config/filament-solaris.php`. Key options include:

- **AI provider & model** — package-wide defaults, per-preset overrides, failover arrays
- **Transcription provider** — separate provider/model/timeout for `DictationFieldAction`
- **Image generation** — separate provider/model/timeout/size/quality/storage for `ImageGenerationAction`
- **Timeout** — default timeout for AI calls
- **Icons** — `action_icon` for AiAction, `dictation_icon` for DictationFieldAction, `image_generation_icon` for ImageGenerationAction
- **Factory map** — custom component-to-factory mappings (includes `FileUploadFactory` for image targets)
- **Prompt logging** — log composed prompts and image generation calls during development
- **Locales** — supported locales for the translation preset

See the [Configuration Reference](documentation/configuration.md) for all available options.

### Per-Panel Configuration (Plugin)

For apps with multiple Filament panels — typically an admin panel plus customer/partner panels — register `FilamentSolarisPlugin` on each panel to override the global defaults. Every setting in `config/filament-solaris.php` that's worth varying per audience is exposed as a fluent setter:

```php
use Statikbe\FilamentSolaris\FilamentSolarisPlugin;

// app/Providers/Filament/AdminPanelProvider.php
$panel->plugin(
    FilamentSolarisPlugin::make()
        ->defaultProvider('anthropic', 'claude-sonnet-4-5')
        ->defaultTemperature(0.3)
        ->defaultMaxTokens(4096)
        ->actionIcon('heroicon-o-sparkles')
        ->locales(['en', 'nl', 'fr'])
        ->promptLogging(true)
);

// app/Providers/Filament/CustomerPanelProvider.php
$panel->plugin(
    FilamentSolarisPlugin::make()
        ->defaultProvider('google', 'gemini-2.5-flash')   // cheaper for end-user features
        ->defaultMaxTokens(1024)                           // tighter token budget
        ->defaultImageDisk('public')
        ->visible(fn () => auth()->user()?->plan === 'pro') // hide AI for free-tier users
);
```

Available setters (every Tier-1/2 config key has one):

- **Provider/model/timeout:** `defaultProvider()`, `defaultModel()`, `defaultTimeout()`
- **Text-gen options:** `defaultTemperature()`, `defaultMaxTokens()`, `defaultMaxSteps()`, `defaultTopP()`
- **Transcription:** `defaultTranscriptionProvider()`, `defaultTranscriptionModel()`, `defaultTranscriptionTimeout()`
- **Image generation:** `defaultImageProvider()`, `defaultImageModel()`, `defaultImageTimeout()`, `defaultImageSize()`, `defaultImageQuality()`, `defaultImageDisk()`, `defaultImageDirectory()`, `defaultImageVisibility()`
- **Logging:** `promptLogging()`, `promptLoggingChannel()`
- **Locales:** `defaultLocale()`, `locales()`
- **Icons:** `actionIcon()`, `dictationIcon()`, `imageGenerationIcon()`, `conversationSendIcon()`, `conversationAttachmentIcon()`
- **Tone:** `defaultTone()`
- **Preset overrides:** `presetProvider()` (single, repeatable) and `presetProviders()` (bulk merge)
- **Visibility gate:** `visible(bool|Closure)` and `disabled()`

**Visibility gate (`->visible(...)` / `->disabled()`).** Set a single panel-wide predicate; Solaris registers `->hidden(...)` on every Solaris action with the negated check, so it hard-AND's with whatever the consuming action sets via its own `->visible(...)`. Users can't accidentally show an action on a disabled panel.

**preset_providers merge semantics.** `presetProvider()` overrides one entry from `config/filament-solaris.php` at a time; entries you don't override stay live. `presetProviders([...])` merges with any prior `presetProvider()` calls on the same plugin.

**Outside a panel context** (queued jobs, CLI commands, non-panel Livewire components) Solaris falls through to `config/filament-solaris.php` — the plugin only applies when `Filament::getCurrentPanel()` returns a panel that registered it.

## Full Example

A complete resource form with multiple AI actions:

```php
use Filament\Forms;
use Statikbe\FilamentSolaris\Actions\AiAction;
use Statikbe\FilamentSolaris\Actions\DictationFieldAction;
use Statikbe\FilamentSolaris\Actions\ImageGenerationAction;
use Statikbe\FilamentSolaris\Enums\ImageSize;
use Statikbe\FilamentSolaris\Prompts\Presets\ClassificationPreset;
use Statikbe\FilamentSolaris\Prompts\Presets\GenerationPreset;
use Statikbe\FilamentSolaris\Prompts\Presets\SummaryPreset;
use Statikbe\FilamentSolaris\Prompts\Presets\TranslationPreset;
use Statikbe\FilamentSolaris\Support\UserInput;

public function form(Form $form): Form
{
    return $form->schema([
        Forms\Components\TextInput::make('title')
            ->required(),

        Forms\Components\RichEditor::make('body')
            ->required(),

        Forms\Components\Textarea::make('summary')
            ->hintAction(
                // Voice-to-summary: transcribe audio and run through AI
                DictationFieldAction::make('voice-summary')
                    ->preset(SummaryPreset::make()->maxWords(100))
                    ->lang('en'),
            ),

        Forms\Components\Select::make('category_id')
            ->relationship('category', 'name'),

        Forms\Components\Toggle::make('is_featured'),

        Forms\Components\Actions::make([
            // Summarize the article
            AiAction::make('summarize')
                ->sourceFields(['title', 'body'])
                ->targetField('summary')
                ->preset(SummaryPreset::make()->maxWords(100)->tone('professional')),

            // Classify into a category (using a cheaper model)
            AiAction::make('classify')
                ->sourceFields(['title', 'body'])
                ->targetField('category_id')
                ->targetScope('category_id', fn ($q) => $q->where('active', true))
                ->preset(ClassificationPreset::make())
                ->provider('openai', 'gpt-4o-mini'),

            // Fill multiple fields at once
            AiAction::make('auto-fill')
                ->sourceFields(['title', 'body'])
                ->targetFields(['summary', 'category_id', 'is_featured'])
                ->prompt('Analyze the article. Summarize it, pick the best category, and decide if it should be featured.'),

            // Generate content with user guidance
            AiAction::make('generate')
                ->sourceFields(['title'])
                ->targetField('body')
                ->preset(GenerationPreset::make()->tone('casual')->audience('developers'))
                ->withDefaultUserInput(),

            // Translate into Dutch
            AiAction::make('translate')
                ->sourceFields(['body'])
                ->targetField('body_nl')
                ->preset(TranslationPreset::make()->language('nl')->preserveFormatting()),

            // Generate a cover image from the article content
            ImageGenerationAction::make('generate-cover')
                ->prompt('Generate a cover image for this article')
                ->sourceFields(['title', 'body'])
                ->targetField('cover_image')
                ->imageSize(ImageSize::Landscape),
        ]),

        Forms\Components\SpatieMediaLibraryFileUpload::make('cover_image')
            ->collection('cover')
            ->disk('public')
            ->image(),
    ]);
}
```

## Versioning

Solaris ships as `0.x` while [`laravel/ai`](https://github.com/laravel/ai) is pre-1.0. The upstream SDK is itself `0.x` and doesn't promise SemVer guarantees, so every `laravel/ai` minor bump (`0.6` → `0.7`) is expected to require a Solaris release. Solaris will tag `1.0.0` once `laravel/ai` reaches `1.0` and its public surface settles.

While on `0.x`:

- **Minor** (`0.1` → `0.2`) — breaking changes are possible.
- **Patch** (`0.1.0` → `0.1.1`) — bugfixes, backwards-compatible additions.

Pin a minor in your `composer.json` if you want to avoid surprises:

```json
"statikbe/laravel-filament-solaris": "~0.1.0"
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Sten Govaerts](https://github.com/sten)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
