<img src="documentation/banner.png" width="800" alt="Laravel Filament Solaris">

# Laravel Filament Solaris

AI actions for Filament v4 & v5 — auto-detect form fields, compose prompts, and write structured AI responses back. Powered by [laravel/ai](https://github.com/laravel/ai).

[![Latest Version on Packagist](https://img.shields.io/packagist/v/statikbe/laravel-filament-solaris.svg?style=flat-square)](https://packagist.org/packages/statikbe/laravel-filament-solaris)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/statikbe/laravel-filament-solaris/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/statikbe/laravel-filament-solaris/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/statikbe/laravel-filament-solaris/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/statikbe/laravel-filament-solaris/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/statikbe/laravel-filament-solaris.svg?style=flat-square)](https://packagist.org/packages/statikbe/laravel-filament-solaris)

## Table of Contents

- [How It Works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Architecture](#architecture)
- [AiAction API](#aiaction-api)
  - [Source Fields](#source-fields)
  - [Target Fields](#target-fields)
  - [Prompts](#prompts)
  - [Presets](#presets)
  - [User Input](#user-input)
  - [Locale](#locale)
  - [Preview](#preview)
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
- [Testing](#testing)
- [Configuration](#configuration)
- [Changelog](#changelog)
- [License](#license)

## How It Works

`AiAction` is a Filament `Action` that reads values from source fields, sends them to an AI provider via `laravel/ai`, and writes structured responses back into target fields. The package auto-detects target component types (Select, TextInput, Toggle, etc.) and handles bidirectional data transformation — converting form state to prompt context and AI responses back to valid form state.

```mermaid
flowchart LR
    A[Source Fields] -->|read values| B[AiAction]
    G[UserInput] -->|modal form data| B
    B -->|compose prompt| C[PromptBuilder]
    C -->|structured request| D[laravel/ai]
    D -->|JSON response| E[ComponentFactory]
    E -->|transform & write| F[Target Fields]
```

## Requirements

- PHP ^8.3
- Filament ^4.2 || ^5.0
- laravel/ai ^0.1
- ext-intl

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

Closures are supported on: `sourceFields()`, `targetFields()`, `locale()`, `userInput()`, and all preset setters (e.g., `maxWords()`, `tone()`, `language()`, `style()`, etc.). Static values continue to work unchanged.

### Preview

Enable a preview modal that shows the AI result before applying it to the form:

```php
->withPreview()
```

## Component Factories

Factories are the bridge between Filament components and AI. Each factory implements three methods:

| Method | Purpose |
|---|---|
| `responseSchema(JsonSchema $schema): Type` | Returns the JSON schema fragment for this field, constraining the AI's output format |
| `toFormValue(mixed $aiValue): mixed` | Transforms the AI's raw response into valid Filament form state |
| `toPromptContext(mixed $formValue): mixed` | Transforms the current form state into a human-readable string for the prompt |

### Supported Components

| Filament Component | Factory | AI Schema | Notes |
|---|---|---|---|
| `Select` | `SelectFactory` | `string` enum or free-text | Enum mode for ≤100 options, free-text with fuzzy matching for >100 |
| `Radio` | `RadioFactory` | `string` enum or free-text | Extends `SelectFactory` |
| `CheckboxList` | `CheckboxListFactory` | `array` of `string` | Multi-select, same matching as Select |
| `TextInput` | `TextFactory` | `string` | Respects `maxLength` if set on the component |
| `Textarea` | `TextFactory` | `string` | Same as TextInput |
| `MarkdownEditor` | `TextFactory` | `string` | Treated as plain text |
| `RichEditor` | `RichEditorFactory` | `string` (HTML) | AI returns HTML, factory converts to TipTap JSON for form state |
| `Toggle` | `BooleanFactory` | `boolean` | Handles string/int coercion ("true", "yes", 1 → true) |
| `Checkbox` | `BooleanFactory` | `boolean` | Same as Toggle |

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

## Testing

If you want to write tests, please check [the testing documentation](documentation/testing.md).

## Configuration

The configuration is published to `config/filament-solaris.php`. See the [Configuration Reference](documentation/configuration.md) for all available options.

## Full Example

A complete resource form with multiple AI actions:

```php
use Filament\Forms;
use Statikbe\FilamentSolaris\Actions\AiAction;
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

        Forms\Components\Textarea::make('summary'),

        Forms\Components\Select::make('category_id')
            ->relationship('category', 'name'),

        Forms\Components\Toggle::make('is_featured'),

        Forms\Components\Actions::make([
            // Summarize the article
            AiAction::make('summarize')
                ->sourceFields(['title', 'body'])
                ->targetField('summary')
                ->preset(SummaryPreset::make()->maxWords(100)->tone('professional')),

            // Classify into a category
            AiAction::make('classify')
                ->sourceFields(['title', 'body'])
                ->targetField('category_id')
                ->targetScope('category_id', fn ($q) => $q->where('active', true))
                ->preset(ClassificationPreset::make()),

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
        ]),
    ]);
}
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Sten Govaerts](https://github.com/sten)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
