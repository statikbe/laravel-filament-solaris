# 09 — Presets

## Summary

Presets are first-class, typed objects representing common AI tasks. Each preset is a `PromptBuilder` implementation with its own Blade template and fluent configuration API. Presets provide IDE autocompletion, type safety, and testability. They can also declare default user input fields they expect at runtime.

## Abstract Base: Preset

```php
namespace Statikbe\FilamentSolaris\Presets;

use Statikbe\FilamentSolaris\Contracts\PromptBuilder;
use Statikbe\FilamentSolaris\Support\UserInput;

abstract class Preset implements PromptBuilder
{
    protected Lab|array|string|null $presetProvider = null;
    protected ?string $presetModel = null;

    public static function make(): static
    {
        return new static();
    }

    /**
     * Set the provider (and optionally model) for this preset.
     * Supports failover arrays: ->provider(['openai' => 'gpt-4o', 'anthropic'])
     */
    public function provider(Lab|array|string $provider, ?string $model = null): static
    {
        $this->presetProvider = $provider;
        if ($model !== null) {
            $this->presetModel = $model;
        }
        return $this;
    }

    public function getProvider(): Lab|array|string|null { return $this->presetProvider; }
    public function getModel(): ?string { return $this->presetModel; }

    /**
     * The Blade view path for this preset's prompt template.
     */
    abstract protected function promptView(): string;

    /**
     * Preset-specific variables passed to the Blade template.
     */
    abstract protected function viewData(): array;

    /**
     * Optional: suggest default UserInput fields for this preset.
     * Return null if this preset doesn't need runtime user input.
     */
    public function defaultUserInput(): ?UserInput
    {
        return null;
    }

    /**
     * PromptBuilder implementation.
     * Ignores $instruction parameter — presets have their own templates.
     */
    public function build(
        string|\Illuminate\Contracts\View\View $instruction,
        array $sourceData,
        array $factories,
        ?\Illuminate\Database\Eloquent\Model $record = null,
        ?string $locale = null,
        array $userInput = [],
    ): string {
        // Resolve locale name
        $localeName = $locale && $locale !== 'en'
            ? \Locale::getDisplayLanguage($locale, 'en')
            : null;

        return view($this->promptView(), [
            'sourceData' => $sourceData,
            'factories' => $factories,
            'record' => $record,
            'locale' => $locale,
            'localeName' => $localeName,
            'userInput' => $userInput,
            ...$this->viewData(),
        ])->render();
    }
}
```

---

## SummarizePreset

Generates a summary of source content into a text target field.

### Configuration

| Method | Type | Default | Description |
|---|---|---|---|
| `maxWords(int)` | int | 200 | Maximum word count for the summary |
| `tone(string)` | string | 'neutral' | Tone of the summary |
| `language(string)` | ?string | null | Override output language |

### Behavior

#### Given SummarizePreset with maxWords(100) and tone('formal')
- When `build()` is called
- Then the rendered prompt instructs the AI to summarize in at most 100 words with a formal tone

#### Given SummarizePreset with language('fr')
- When `build()` is called
- Then the prompt instructs the AI to write the summary in French
- This overrides the locale parameter

### Template: `resources/views/prompts/summarize.blade.php`

Includes:
- Instruction to summarize the provided source data
- Word count constraint
- Tone instruction
- Language instruction (if set)
- Locale hint (if locale is non-English and no explicit language)
- User input section (if provided)
- JSON response schema from factories

---

## ClassifyPreset

Classifies source content into one or more categories from the target field's options.

### Configuration

| Method | Type | Default | Description |
|---|---|---|---|
| `allowMultiple(bool)` | bool | false | Whether the AI can select multiple categories |
| `context(string)` | ?string | null | Additional context about the classification domain |

### Behavior

#### Given ClassifyPreset with context('technology blog')
- When `build()` is called
- Then the prompt includes "This content is from a technology blog" as context

#### Given ClassifyPreset with allowMultiple(true) targeting a CheckboxList
- When `build()` is called
- Then the prompt instructs the AI that multiple selections are allowed

#### Given ClassifyPreset with allowMultiple(false) targeting a Select
- When `build()` is called
- Then the prompt instructs the AI to pick exactly one option

### Template: `resources/views/prompts/classify.blade.php`

Includes:
- Instruction to classify the content
- Available options listed from factory schemas
- Single vs multiple selection instruction
- Context (if set)
- Source data
- JSON response schema

---

## TranslatePreset

Translates source content into a target language.

### Configuration

| Method | Type | Default | Description |
|---|---|---|---|
| `language(string)` | string | **required** | Target language for translation |
| `preserveFormatting(bool)` | bool | true | Whether to maintain HTML/Markdown formatting |
| `glossary(string)` | ?string | null | Domain-specific glossary or terminology notes |

### Behavior

#### Given TranslatePreset without language() set
- When `build()` is called
- Then it throws a `RuntimeException`: "TranslatePreset requires a target language. Call ->language('fr') before using."

#### Given TranslatePreset with glossary('API = API, user = utilisateur')
- When `build()` is called
- Then the prompt includes the glossary as terminology the AI should follow

#### Given TranslatePreset with preserveFormatting(true) and source containing HTML
- When `build()` is called
- Then the prompt instructs the AI to maintain the original HTML structure

### Default User Input

```php
public function defaultUserInput(): ?UserInput
{
    return UserInput::make()
        ->fields([
            Select::make('language')
                ->label('Translate to')
                ->options($this->availableLanguages())
                ->required(),
        ]);
}
```

If the developer calls `->withDefaultUserInput()`, the end user picks the target language at runtime. The selected language is available in `$userInput['language']` and the preset template uses it.

### Template: `resources/views/prompts/translate.blade.php`

Includes:
- Instruction to translate to the target language
- Formatting preservation instruction
- Glossary (if set)
- Source data
- JSON response schema

---

## GeneratePreset

Generates new content based on source data and user instructions.

### Configuration

| Method | Type | Default | Description |
|---|---|---|---|
| `tone(string)` | ?string | null | Desired tone |
| `maxLength(int)` | ?int | null | Maximum length in words |
| `style(string)` | ?string | null | Writing style (e.g., 'blog post', 'technical', 'marketing') |
| `audience(string)` | ?string | null | Target audience description |

### Behavior

#### Given GeneratePreset with tone('casual') and audience('developers')
- When `build()` is called
- Then the prompt instructs the AI to write in a casual tone for a developer audience

#### Given GeneratePreset with no configuration
- When `build()` is called
- Then the prompt gives the AI broad creative freedom guided by the source data

### Default User Input

```php
public function defaultUserInput(): ?UserInput
{
    return UserInput::make()
        ->prompt('What would you like to generate?')
        ->placeholder('Describe what you want...');
}
```

### Template: `resources/views/prompts/generate.blade.php`

Includes:
- Instruction to generate content based on source data and user input
- Tone, style, audience constraints (if set)
- Length constraint (if set)
- User input (primary driver for this preset)
- JSON response schema

---

## Provider / Model Selection

Presets can declare a preferred AI provider and model. This participates in the resolution chain (see spec 14):

```php
// Single provider + model
SummaryPreset::make()
    ->provider('openai', 'gpt-4o')
    ->maxWords(200)

// Failover array (tries in order)
ClassificationPreset::make()
    ->provider(['openai' => 'gpt-4o-mini', 'anthropic'])

// Lab enum
TranslationPreset::make()
    ->provider(Lab::Anthropic, 'claude-sonnet-4-5-20250514')
```

The preset's provider is overridden by an action-level `->provider()` if set. If the preset has no provider, the resolution chain checks `config('filament-solaris.preset_providers')` for a per-class override, then falls through to the package default or laravel/ai default.

---

## Custom Presets

Developers create custom presets by extending `Preset`:

```php
class SeoMetaPreset extends Preset
{
    protected int $titleMaxChars = 60;
    protected int $descriptionMaxChars = 160;

    public function titleMaxChars(int $max): static
    {
        $this->titleMaxChars = $max;
        return $this;
    }

    protected function promptView(): string
    {
        return 'my-package::prompts.seo-meta';
    }

    protected function viewData(): array
    {
        return [
            'titleMaxChars' => $this->titleMaxChars,
            'descriptionMaxChars' => $this->descriptionMaxChars,
        ];
    }
}
```
