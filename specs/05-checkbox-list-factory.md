# 05 — CheckboxListFactory

## Summary

The `CheckboxListFactory` handles Filament `CheckboxList` components where the AI needs to select one or more options from a set. Similar to SelectFactory but returns an array of values instead of a single value.

## Class: CheckboxListFactory extends ComponentFactory

### Configuration

- `$maxOptions` (int, default 100): Same threshold as SelectFactory for large option sets.

## Option Resolution

Same behavior as SelectFactory (spec 03). Supports static options, Closure-based options, relationship-based options, and targetScope.

## Response Schema

### Given fewer than or equal to maxOptions options
- When `responseSchema()` is called
- Then it returns a JSON schema with `type: "array"` and `items` containing `type: "string"` with `enum` of all option keys
- And the `description` says "Pick one or more" and lists all options as `"key (label)"` pairs

### Given more than maxOptions options
- When `responseSchema()` is called
- Then it returns a JSON schema with `type: "array"` and `items` containing `type: "string"` (no enum)
- And the `description` includes total count, a sample of 10 labels, and "Return exact labels"

## toFormValue (AI response → form state)

### Given an AI response that is an array of valid option keys
- When `toFormValue()` is called
- Then it returns the array as-is

### Given an AI response that is a single string (not an array)
- When `toFormValue()` is called
- Then it wraps it in an array: `[$aiValue]`

### Given an AI response array containing labels instead of keys
- When `toFormValue()` is called
- Then each label is resolved to its key using the same fuzzy matching logic as SelectFactory
- And the result is an array of resolved keys

### Given an AI response array with some valid and some invalid values
- When `toFormValue()` is called
- Then it resolves what it can and filters out unresolvable values
- And logs a warning for the unresolvable values

## toPromptContext (form state → prompt)

### Given a form value that is an array of option keys
- When `toPromptContext()` is called
- Then it maps each key to its label
- And returns a comma-separated string of labels

### Given a form value that is null or empty array
- When `toPromptContext()` is called
- Then it returns "None selected"

## Shared Logic with SelectFactory

`CheckboxListFactory` shares option resolution and fuzzy matching logic with `SelectFactory`. Extract these into a shared trait or base class:

- `resolveOptions(): array`
- `fuzzyMatch(string $aiValue, array $options): mixed`
- `resolveOptionKey(mixed $aiValue, array $options): mixed`

Consider a `HasOptions` trait that both `SelectFactory` and `CheckboxListFactory` use.
