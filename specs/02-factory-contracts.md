# 02 — Factory Contracts

## Summary

The `ComponentFactory` is the central abstraction in the package. It acts as a bidirectional transformer between Filament form components and AI-compatible data formats. Each factory knows how to describe its component's expected output as a JSON schema, transform AI responses into valid form state, and transform form state into prompt-friendly context.

## Interface: ComponentFactory

```php
namespace Statikbe\FilamentSolaris\Contracts;

interface ComponentFactory
{
    /**
     * JSON schema fragment describing valid AI output for this field.
     * Used by the PromptBuilder to instruct the AI on response format.
     *
     * @return array JSON Schema-compatible array
     */
    public function responseSchema(): array;

    /**
     * Transform AI response value into Filament form state.
     * Called after the AI responds, before setting the value on the form.
     * Must handle edge cases: wrong type, label instead of key, near-matches.
     *
     * @param mixed $aiValue The raw value from the AI's JSON response
     * @return mixed Value suitable for Filament's form state
     */
    public function toFormValue(mixed $aiValue): mixed;

    /**
     * Transform form state into prompt-friendly context.
     * Called when building the prompt, to represent current field values
     * in a way the AI can understand.
     *
     * @param mixed $formValue The current value from Filament's form state
     * @return mixed Human-readable representation for the prompt
     */
    public function toPromptContext(mixed $formValue): mixed;
}
```

## Abstract Base Class

All concrete factories extend this base, which handles the common constructor pattern:

```php
namespace Statikbe\FilamentSolaris\Factories;

use Filament\Forms\Components\Component;
use Statikbe\FilamentSolaris\Contracts\ComponentFactory as ComponentFactoryContract;

abstract class ComponentFactory implements ComponentFactoryContract
{
    public function __construct(
        protected Component $component,
        protected ?\Closure $scope = null,
    ) {}

    public static function make(Component $component, ?\Closure $scope = null): static
    {
        return new static($component, $scope);
    }

    /**
     * Get the Filament component this factory wraps.
     */
    public function getComponent(): Component
    {
        return $this->component;
    }

    /**
     * Get the optional scope closure.
     */
    public function getScope(): ?\Closure
    {
        return $this->scope;
    }
}
```

## ComponentFactoryResolver

Responsible for walking the Filament form component tree, finding a target field's component, and instantiating the correct factory.

### Factory Map

Default mapping of Filament component classes to factory classes:

| Filament Component | Factory |
|---|---|
| `Select` | `SelectFactory` |
| `Radio` | `RadioFactory` |
| `CheckboxList` | `CheckboxListFactory` |
| `Toggle` | `BooleanFactory` |
| `Checkbox` | `BooleanFactory` |
| `TextInput` | `TextFactory` |
| `Textarea` | `TextFactory` |
| `RichEditor` | `RichEditorFactory` |
| `MarkdownEditor` | `MarkdownFactory` |
| `ToggleButtons` | `SelectFactory` |
| `TagsInput` | `TagsFactory` |
| `CodeEditor` | `TextFactory` |

### Behavior

#### Given a target field name and a Livewire component
- When `resolve()` is called
- Then it walks the form's flat component list to find the matching component
- And returns an instance of the mapped factory class, passing the component and optional scope

#### Given a target field name that does not exist in the form
- When `resolve()` is called
- Then it throws a `RuntimeException` with message "Component '{name}' not found in form."

#### Given a component class that has no mapped factory
- When `resolve()` is called
- Then it throws a `RuntimeException` with message "No AI factory registered for {ComponentClass}. Register one via FilamentSolaris::registerFactory()."

#### Given a custom factory registration
- When `FilamentSolaris::registerFactory(MyCustomComponent::class, MyFactory::class)` has been called
- Then the resolver uses `MyFactory` for `MyCustomComponent` fields

### Registration API

Developers can extend the factory map via the service provider or a plugin:

```php
// In a service provider's boot method
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;

FilamentSolaris::registerFactory(MyCustomComponent::class, MyCustomFactory::class);
```

### Nested Component Resolution

Filament forms can have nested components inside Sections, Tabs, Grids, etc. The resolver must use `getFlatComponents()` or equivalent to search through all nesting levels. The resolver matches on `getStatePath()` first, falling back to `getName()`.
