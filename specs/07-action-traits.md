# 07 — Action Traits

## Summary

The action traits encapsulate the source field, target field, and user input configuration on `AiAction`. They handle data collection, scope application, and factory resolution.

## Trait: HasSourceFields

### Properties

- `$sourceFields` (array): List of form field names to read from
- `$sourceScopes` (array): Keyed by field name, each value is a Closure that transforms the field value before it enters the prompt

### Methods

#### `sourceFields(array $fields): static`
Sets the list of source field names.

#### `sourceScope(string $field, \Closure $scope): static`
Registers a value transformer for a specific source field. The Closure receives the raw form value and returns the transformed value.

#### `getSourceFieldValues(): array`
Collects values from the Livewire component's form state for all registered source fields. Applies sourceScope closures where configured.

### Behavior

#### Given source fields ['title', 'body'] and no scopes
- When `getSourceFieldValues()` is called
- Then it returns `['title' => $formState['title'], 'body' => $formState['body']]`

#### Given a sourceScope on 'body' that truncates to 2000 chars
- When `getSourceFieldValues()` is called
- Then the 'body' value is passed through the scope closure before being included

#### Given a source field name that doesn't exist in form state
- When `getSourceFieldValues()` is called
- Then that field's value is `null` in the result (no exception)

---

## Trait: HasTargetFields

### Properties

- `$targetFields` (array): List of form field names to write AI results to
- `$targetScopes` (array): Keyed by field name, each value is a Closure applied to the factory's query resolution
- `$preview` (bool, default false): Whether to show a preview modal before applying values

### Methods

#### `targetField(string $field): static`
Adds a target field. Can be called multiple times for multi-target actions.

#### `targetFields(array $fields): static`
Sets multiple target fields at once. Replaces any previously set targets.

#### `targetScope(string $field, \Closure $scope): static`
Registers a query scope for relationship-based target fields. Passed to the factory during option resolution.

#### `withPreview(bool $preview = true): static`
Enables or disables the preview modal for this action.

#### `resolveTargetFactories(): array`
For each target field, uses `ComponentFactoryResolver` to find the Filament component and instantiate the appropriate factory. Returns an associative array keyed by field name.

### Behavior

#### Given targetField called twice with 'category_id' and 'priority'
- When `resolveTargetFactories()` is called
- Then it returns factories for both fields: `['category_id' => SelectFactory, 'priority' => SelectFactory]`

#### Given a targetScope on 'agent_id'
- When `resolveTargetFactories()` is called
- Then the scope closure is passed to the factory constructor for 'agent_id'

#### Given a target field that doesn't exist in the form
- When `resolveTargetFactories()` is called
- Then it throws a `RuntimeException` (propagated from ComponentFactoryResolver)

---

## Trait: HasUserInput

### Properties

- `$userInput` (?UserInput): The user input configuration, or null if not configured

### Methods

#### `userInput(UserInput $userInput): static`
Sets the user input configuration.

#### `withDefaultUserInput(): static`
If a preset is configured and it provides `defaultUserInput()`, use that. Otherwise no-op.

#### `hasUserInput(): bool`
Returns whether user input is configured.

#### `getUserInputFormSchema(): array`
Returns the Filament form schema for the user input modal. Delegates to `UserInput::toFormSchema()`.

### Behavior

#### Given a UserInput configured with a placeholder
- When `hasUserInput()` is called
- Then it returns true
- And `getUserInputFormSchema()` returns a schema with a single Textarea

#### Given a UserInput configured with custom fields
- When `getUserInputFormSchema()` is called
- Then it returns the custom Filament form components

#### Given no UserInput configured
- When `hasUserInput()` is called
- Then it returns false
- And the action executes without opening a modal for user instructions
