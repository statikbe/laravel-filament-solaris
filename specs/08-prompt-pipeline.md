# 08 — Prompt Pipeline

## Summary

The prompt pipeline composes the final string sent to the AI agent. It combines the developer's instruction, source data, factory-generated schemas, optional record data, locale hints, and end-user input into a single coherent prompt. The pipeline is abstracted behind the `PromptBuilder` interface, with three shipped implementations.

## Interface: PromptBuilder

```php
namespace Statikbe\FilamentSolaris\Contracts;

use Illuminate\Database\Eloquent\Model;

interface PromptBuilder
{
    /**
     * Compose the final prompt string for the AI agent.
     *
     * @param string|\Illuminate\Contracts\View\View $instruction Developer's instruction
     * @param array $sourceData Source field values (after scopes)
     * @param array<string, ComponentFactory> $factories Target field factories keyed by field name
     * @param Model|null $record Current Eloquent model (null on create pages)
     * @param string|null $locale Target locale (null = app locale)
     * @param array $userInput End-user's runtime input from modal
     * @return string The composed prompt
     */
    public function build(
        string|\Illuminate\Contracts\View\View $instruction,
        array $sourceData,
        array $factories,
        ?\Illuminate\Database\Eloquent\Model $record = null,
        ?string $locale = null,
        array $userInput = [],
    ): string;
}
```

## Implementation: InlinePromptBuilder

Used when the developer passes a plain string via `->prompt('Classify this article')`.

### Behavior

#### Given an inline string instruction
- When `build()` is called
- Then it renders the `base-wrapper.blade.php` template
- With `$instruction` set to the string
- And `$sourceData`, `$factories`, `$record`, `$locale`, `$userInput` passed through

#### Given locale is 'en' or null
- When the base wrapper renders
- Then no locale hint is included in the prompt

#### Given locale is 'nl'
- When the base wrapper renders
- Then a locale hint is appended: "The instruction above may be in Dutch. Respond in Dutch."

#### Given userInput is non-empty
- When the base wrapper renders
- Then the user's input is included as additional instructions

#### Given userInput is empty
- When the base wrapper renders
- Then the user input section is omitted entirely

## Base Wrapper Template

`resources/views/prompts/base-wrapper.blade.php`

This template is used by `InlinePromptBuilder` and serves as a reference for custom implementations.

### Structure

1. System preamble (English, fixed): "You are an AI assistant integrated into a form interface."
2. Developer instruction: `{{ $instruction }}`
3. User input section (conditional): end-user's additional instructions
4. Locale hint (conditional): language instruction for non-English locales
5. Source data section: key-value listing of source field values
6. Response format section: JSON schema built from factory instances

### Variables Available

| Variable | Type | Description |
|---|---|---|
| `$instruction` | string | Developer's prompt text |
| `$sourceData` | array | Source field values (key => value) |
| `$factories` | array | ComponentFactory instances keyed by field name |
| `$record` | ?Model | Current Eloquent record or null |
| `$locale` | ?string | Target locale code |
| `$localeName` | ?string | Human-readable locale name (e.g., "Dutch") |
| `$userInput` | array | End-user's input from the modal form |

### JSON Schema Rendering

The response format section iterates over `$factories` and calls `responseSchema()` on each to build a combined JSON object schema:

```json
{
    "field_name_1": { /* factory 1 schema */ },
    "field_name_2": { /* factory 2 schema */ }
}
```

For single-target actions, the schema is still wrapped in an object with one key for consistency.

## Implementation: ViewPromptBuilder

Used when the developer passes a Blade view via `->prompt(view('my-custom-prompt'))`.

### Behavior

#### Given a Blade view
- When `build()` is called
- Then it renders the view with all standard variables (`$instruction`, `$sourceData`, `$factories`, `$record`, `$locale`, `$localeName`, `$userInput`)
- And returns the rendered string

The developer is fully responsible for the prompt content, including the JSON schema section. The factories are available for iteration in the template.

## Locale Resolution

### Priority order:
1. Explicit `->locale('nl')` on the action
2. `app()->getLocale()`
3. Fall back to `'en'`

### Locale name mapping
Use `Locale::getDisplayLanguage($locale, 'en')` from PHP's intl extension to get human-readable names. E.g., `'nl'` → `'Dutch'`, `'fr'` → `'French'`.

If the intl extension is not available, maintain a basic mapping of common locale codes.
