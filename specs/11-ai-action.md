# 11 — AiAction

## Summary

`AiAction` is the main Filament action class that orchestrates the entire AI pipeline. It extends Filament's `Action` class and uses the traits defined in spec 07. It is the integration point where source fields, target fields, prompt building, AI execution, and result handling come together.

## Class: AiAction extends Filament\Actions\Action

### Traits Used
- `HasSourceFields`
- `HasTargetFields`
- `HasUserInput`

### Additional Properties

- `$promptBuilder` (?PromptBuilder): The prompt builder instance. Set via `->prompt()` or `->preset()`.
- `$locale` (?string): Override locale. Defaults to `app()->getLocale()`.

## Configuration API

### Prompt Configuration

#### `prompt(string $instruction): static`
Sets an inline string instruction. Internally creates an `InlinePromptBuilder`.

#### `prompt(\Illuminate\Contracts\View\View $view): static`
Sets a Blade view as the prompt. Internally creates a `ViewPromptBuilder`.

#### `preset(Preset $preset): static`
Sets a Preset as the PromptBuilder. The preset itself implements `PromptBuilder`.

#### `promptBuilder(PromptBuilder $builder): static`
Sets a custom PromptBuilder instance directly. Escape hatch for advanced use cases.

#### `locale(string $locale): static`
Overrides the locale for this action. Affects the locale hint in the prompt.

#### `provider(Lab|array|string|Closure $provider, string|Closure|null $model = null): static`
Sets the AI provider and model for this action (inherited from `HasPromptPipeline`). Supports single providers, Lab enums, failover arrays, and closures. See spec 14 for the full resolution chain.

### Full Configuration Example

```php
AiAction::make('classify-and-summarize')
    ->sourceFields(['title', 'body'])
    ->sourceScope('body', fn ($v) => str($v)->limit(3000)->toString())
    ->targetField('category_id')
    ->targetScope('category_id', fn ($q) => $q->where('active', true))
    ->targetField('summary')
    ->preset(ClassifyPreset::make()->context('tech blog'))
    ->userInput(UserInput::make()->placeholder('Any specific instructions?'))
    ->withPreview()
    ->locale('nl')
    ->provider('anthropic', 'claude-sonnet-4-5-20250514')
```

## Execution Flow

### Step 1: Modal (conditional)

#### Given hasUserInput() is true
- Then the action opens a Filament modal with the UserInput form schema
- The modal submit button is labeled "Generate"
- On submit, form data is collected as `$userInput`

#### Given hasUserInput() is false
- Then the action executes immediately (no modal)

### Step 2: Collect Source Data

- Call `getSourceFieldValues()` from HasSourceFields trait
- Returns associative array of field name => value (with scopes applied)

### Step 3: Resolve Factories

- Call `resolveTargetFactories()` from HasTargetFields trait
- Returns associative array of field name => ComponentFactory instance
- Each factory has the component and optional scope injected

### Step 4: Get Record

- Attempt `$this->getLivewire()->getRecord()` or equivalent
- Returns the Eloquent model on edit pages, null on create pages
- Wrap in try/catch — not all Livewire components have `getRecord()`

### Step 5: Build Prompt

- Determine the PromptBuilder to use (inline, view, or preset)
- Call `$promptBuilder->build()` with all collected data
- Pass: instruction, sourceData, factories, record, locale, userInput

### Step 6: Call AI

- Resolve provider and model via `resolveProviderAndModel()` (see spec 14 for the resolution chain)
- Use laravel-ai to send the composed prompt with the resolved provider/model: `$agent->prompt($prompt, [], $provider, $model)`
- Request JSON response matching the combined schema
- Handle timeout and API errors gracefully

### Step 7: Parse Response

- Parse the JSON response from the AI
- Extract values for each target field by key name

### Step 8: Transform and Apply Results

For each target field:
1. Get the field's value from the AI response
2. Call `factory->toFormValue($aiValue)` to transform it
3. Validate the transformation succeeded (value is usable)

### Step 9: Handle Results

#### Direct Fill Mode (default, withPreview = false)
- For each successfully transformed field:
  set the value on the Livewire component's form state
- Show a success notification: "AI filled {n} fields"
- If some fields failed: show a warning notification listing the failed field names

#### Preview Mode (withPreview = true)
- Open a confirmation modal showing the proposed values
- Each field shown with its label and the proposed value
- User clicks "Accept" → values are applied to the form
- User clicks "Discard" → no changes made

#### Full Failure
- If the AI call itself fails (timeout, API error, invalid JSON):
  show an error notification with a user-friendly message
- Do not modify any form fields

## Error Handling Detail

### Partial Failure

#### Given AI returns valid values for 'category_id' but invalid for 'summary'
- When results are applied
- Then 'category_id' is set on the form
- And 'summary' is not modified
- And a warning notification is shown: "Could not fill: Summary"
- The warning uses the field's label, not its name

### Validation

A field result is considered "failed" when:
- The AI response JSON doesn't contain a key for that field
- `toFormValue()` returns the raw AI value unchanged (indicating no match was found, for option-based factories)
- `toFormValue()` throws an exception

### API Errors

- Timeout: "The AI request timed out. Please try again."
- Rate limit: "Too many AI requests. Please wait a moment."
- Other API error: "Something went wrong with the AI request. Please try again."

## Loading State

While the AI request is in progress:
- The action button shows a loading spinner
- The form fields are not disabled (user can continue editing other fields)
- The action button is disabled to prevent duplicate requests

## Filament Registration

AiAction is a standard Filament Action. Developers register it in their resource or form:

```php
// In a Resource
public static function form(Form $form): Form
{
    return $form
        ->schema([
            TextInput::make('title'),
            Textarea::make('body'),
            Select::make('category_id')->options([...]),
            Textarea::make('summary'),
        ])
        ->headerActions([
            AiAction::make('summarize')
                ->sourceFields(['body'])
                ->targetField('summary')
                ->preset(SummarizePreset::make()->maxWords(200)),
        ]);
}
```

## Validation Before Execution

#### Given no sourceFields configured
- When the action is about to execute
- Then it throws a `RuntimeException`: "AiAction requires at least one source field."

#### Given no targetField configured
- When the action is about to execute
- Then it throws a `RuntimeException`: "AiAction requires at least one target field."

#### Given no prompt, preset, or promptBuilder configured
- When the action is about to execute
- Then it throws a `RuntimeException`: "AiAction requires a prompt, preset, or custom promptBuilder."
