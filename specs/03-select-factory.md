# 03 — SelectFactory

## Summary

The `SelectFactory` transforms Filament `Select` components into AI-compatible JSON schemas and maps AI responses back to valid option keys. It handles static options, relationship-based options, scoped queries, large option sets, and fuzzy matching of AI responses.

## Class: SelectFactory extends ComponentFactory

### Configuration

- `$maxOptions` (int, default 100): Threshold above which the factory switches from strict enum to free-text schema with fuzzy matching.

## Option Resolution

### Given a Select with static options
- When `resolveOptions()` is called
- Then it returns the options array as-is (key => label pairs)

### Given a Select with options defined as a Closure
- When `resolveOptions()` is called
- Then it evaluates the Closure and returns the resulting array

### Given a Select with a belongsTo/belongsToMany relationship
- When no targetScope is provided
- Then it executes the relationship query
- And plucks the title attribute and key
- And returns the result as key => label pairs

### Given a Select with a relationship and a targetScope closure
- When `resolveOptions()` is called
- Then the scope closure is applied to the query before execution
- And the scoped query results are returned as key => label pairs

### Given a Select with no options and no relationship
- When `resolveOptions()` is called
- Then it returns an empty array

## Response Schema

### Given fewer than or equal to maxOptions options
- When `responseSchema()` is called
- Then it returns a JSON schema with `type: "string"` and `enum` containing all option keys
- And the `description` lists all options as `"key (label)"` pairs separated by commas

### Given more than maxOptions options
- When `responseSchema()` is called
- Then it returns a JSON schema with `type: "string"` (no enum constraint)
- And the `description` includes the total count and a sample of 10 option labels
- And a log warning is emitted if count exceeds 500

## toFormValue (AI response → form state)

### Given an AI response that exactly matches an option key
- When `toFormValue()` is called
- Then it returns the key directly

### Given an AI response that exactly matches an option label (not key)
- When `toFormValue()` is called
- Then it reverse-looks up the key via `array_flip` and returns it

### Given an AI response with a case-insensitive match to a label
- When `toFormValue()` is called
- Then it finds the match via `mb_strtolower` comparison and returns the key

### Given an AI response that is a substring of a label
- When `toFormValue()` is called
- Then it finds the match via `str_contains` and returns the key

### Given an AI response with a small typo (Levenshtein distance ≤ 3)
- When `toFormValue()` is called
- Then it finds the closest match via Levenshtein distance and returns the key

### Given an AI response with Levenshtein distance > 3 and no other match
- When `toFormValue()` is called
- Then it returns the raw AI value (will be treated as a failed field by the action)

## toPromptContext (form state → prompt)

### Given a form value that is a valid option key
- When `toPromptContext()` is called
- Then it returns the option's label (human-readable form)

### Given a form value that is null or not a valid key
- When `toPromptContext()` is called
- Then it returns the value as-is

## Fuzzy Match Priority

The matching in `toFormValue` follows this order (first match wins):
1. Exact key match
2. Exact label match (reverse lookup)
3. Case-insensitive label match
4. Substring match (`str_contains`)
5. Levenshtein distance ≤ 3
6. No match → return raw value

## RadioFactory

`RadioFactory` behaves identically to `SelectFactory`. It extends `SelectFactory` directly or shares the same implementation via a trait. The only difference is it accepts `Radio` components instead of `Select`. Radio components expose options via the same `getOptions()` method.
