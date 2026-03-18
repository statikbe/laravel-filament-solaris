# 10 — UserInput

## Summary

`UserInput` defines what the end user can configure at runtime before an AI action executes. It opens a Filament modal with form fields, and the user's responses are passed to the PromptBuilder as additional context. Designed as the foundation for a v2 chat interface.

## Class: UserInput

```php
namespace Statikbe\FilamentSolaris\Support;

class UserInput
{
    protected string $prompt = 'Additional instructions';
    protected ?string $placeholder = null;
    protected ?array $fields = null;

    public static function make(): static { ... }
    public function prompt(string $prompt): static { ... }
    public function placeholder(string $placeholder): static { ... }
    public function fields(array $fields): static { ... }
    public function toFormSchema(): array { ... }
}
```

## Behavior

### Given UserInput with only a placeholder
- When `toFormSchema()` is called
- Then it returns a single Textarea with:
  - name: `user_instructions`
  - label: the `$prompt` value (default "Additional instructions")
  - placeholder: the `$placeholder` value
  - rows: 3

### Given UserInput with custom fields
- When `toFormSchema()` is called
- Then it returns the custom Filament form components as-is
- The developer has full control over field names, types, and validation

### Given UserInput with custom fields including a required Select
- When the modal is submitted without selecting a value
- Then Filament's built-in validation prevents submission
- And the AI action does not execute

## Integration with AiAction

### Modal Flow

1. User clicks the AI action button
2. If `hasUserInput()` is true, the action opens a Filament modal
3. Modal renders the form schema from `getUserInputFormSchema()`
4. User fills in the form and clicks "Generate" (or equivalent submit button)
5. The form data is collected as `$userInput` array
6. `$userInput` is passed to the PromptBuilder's `build()` method

### Button Labels

- Modal submit button: "Generate" (translatable via `filament-solaris::actions.generate`)
- Modal cancel button: "Cancel" (standard Filament cancel)

## Integration with Presets

### Given a Preset with `defaultUserInput()` returning a UserInput instance
- When the developer calls `->withDefaultUserInput()` on the action
- Then the preset's default UserInput is used
- And the developer does not need to configure UserInput manually

### Given both explicit `->userInput()` and `->withDefaultUserInput()`
- When the action is configured
- Then the explicit `->userInput()` takes precedence over the preset's default

### Given a Preset with no `defaultUserInput()` (returns null)
- When `->withDefaultUserInput()` is called
- Then it's a no-op — no modal is shown

## User Input in Prompt Templates

The `$userInput` variable is an associative array in all prompt templates:

### Simple case (default Textarea)
```php
$userInput = ['user_instructions' => 'Keep it under 100 words and focus on financials']
```

### Custom fields case
```php
$userInput = [
    'language' => 'fr',
    'tone' => 'formal',
    'instructions' => 'Focus on the technical aspects',
]
```

### Template usage
```blade
@if(!empty($userInput))
The user has provided these additional instructions:
@foreach($userInput as $key => $value)
@if(filled($value))
- {{ str($key)->headline() }}: {{ $value }}
@endif
@endforeach
@endif
```

## v2 Evolution: Chat Interface

In v2, UserInput evolves to support multi-turn interaction:

- After the initial AI response, the user can provide follow-up instructions
- The modal becomes a chat-like interface
- Previous AI responses and user messages form a conversation history
- The PromptBuilder receives the full conversation context

The current architecture supports this because:
- `$userInput` is already passed as a separate parameter to `build()`
- The modal is already a Filament action modal that can be extended
- The `build()` method signature can accept conversation history as an additional parameter in v2 without breaking the interface (add it with a default value)

For MVP, the architecture is designed but the chat UI is not implemented.
