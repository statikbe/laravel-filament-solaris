# Presets Reference

[← Back to README](../README.md)

Presets are reusable prompt builders for common AI tasks. Each preset renders its own Blade template with configurable parameters, and plugs into an action via `->preset(...)`.

## SummaryPreset

Generates a summary of the source content.

```php
SummaryPreset::make()
    ->maxWords(200)       // default: 200
    ->tone('professional') // default: config('filament-solaris.default_tone')
    ->language('French')   // overrides locale for output language
```

## ClassificationPreset

Classifies content into the target field's options.

```php
ClassificationPreset::make()
    ->allowMultiple()           // allow selecting multiple categories (for CheckboxList targets)
    ->context('tech blog')      // additional context about the classification domain
```

## TranslationPreset

Translates source content into a target language.

```php
TranslationPreset::make()
    ->language('fr')                // required — target language
    ->preserveFormatting()          // default: true — preserve HTML/Markdown structure
    ->glossary('API = API (never translate), Laravel = Laravel')
```

The `TranslationPreset` defines a `defaultUserInput()` that renders a language selector populated from `supported_locales`. Use `->withDefaultUserInput()` on the action to enable it.

## GenerationPreset

Generates new content based on source data and user instructions.

```php
GenerationPreset::make()
    ->tone('casual')
    ->style('blog post')
    ->audience('developers')
    ->maxLength(500)
```

Defines a `defaultUserInput()` with a "What would you like to generate?" textarea.

## Custom Presets

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
