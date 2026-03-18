# 06 — BooleanFactory

## Summary

The `BooleanFactory` handles Filament `Toggle` and `Checkbox` components. The AI returns a boolean value and the factory maps it to the component's expected form state.

## Class: BooleanFactory extends ComponentFactory

## Response Schema

### Given a Toggle or Checkbox component
- When `responseSchema()` is called
- Then it returns `['type' => 'boolean', 'description' => 'true or false']`

## toFormValue (AI response → form state)

### Given an AI response that is a boolean
- When `toFormValue()` is called
- Then it returns the boolean as-is

### Given an AI response that is the string "true" or "yes" or "1"
- When `toFormValue()` is called
- Then it returns `true`

### Given an AI response that is the string "false" or "no" or "0"
- When `toFormValue()` is called
- Then it returns `false`

### Given an AI response that is an integer 1
- When `toFormValue()` is called
- Then it returns `true`

### Given an AI response that is an integer 0
- When `toFormValue()` is called
- Then it returns `false`

### Given an AI response that cannot be interpreted as boolean
- When `toFormValue()` is called
- Then it returns `false` as a safe default
- And logs a warning

## toPromptContext (form state → prompt)

### Given a form value of true
- When `toPromptContext()` is called
- Then it returns the string "Yes"

### Given a form value of false
- When `toPromptContext()` is called
- Then it returns the string "No"

### Given a form value of null
- When `toPromptContext()` is called
- Then it returns the string "Not set"

## Component Detection

The following Filament component classes map to `BooleanFactory`:
- `Filament\Forms\Components\Toggle`
- `Filament\Forms\Components\Checkbox`
