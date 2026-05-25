# Prompt Builders

[← Back to README](../README.md)

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

## Inline Prompts

Used when you call `->prompt('...')` with a string. Renders the `base-wrapper.blade.php` template which includes:
- A system preamble
- Your instruction
- User input section (if present)
- Locale hint (for non-English locales)
- Source data formatted as key-value pairs
- JSON response schema block

## View Prompts

Used when you call `->prompt(view('...'))`. The provided Blade view is rendered with all standard variables. You control the full prompt structure.

## Custom Prompt Builders

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
AiFormAction::make('custom')
    ->sourceFields(['title'])
    ->targetField('body')
    ->promptBuilder(new MyPromptBuilder())
```
