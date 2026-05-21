# Architecture

[← Back to README](../README.md)

## Execution Pipeline

```mermaid
sequenceDiagram
    participant User
    participant AiAction
    participant PromptBuilder
    participant SolarisAgent
    participant AI as laravel/ai Provider
    participant Factory as ComponentFactory

    User->>AiAction: clicks action button
    Note over AiAction: if UserInput configured
    AiAction->>User: show modal form
    User->>AiAction: submit user input

    AiAction->>AiAction: validate config
    AiAction->>AiAction: collect source field values
    AiAction->>AiAction: resolve target factories

    AiAction->>PromptBuilder: build(instruction, sourceData, factories, record, locale, userInput)
    PromptBuilder-->>AiAction: composed prompt string

    AiAction->>SolarisAgent: configure(prompt, factories)
    SolarisAgent->>AI: prompt() with JSON schema
    AI-->>SolarisAgent: structured JSON response

    loop for each target field
        AiAction->>Factory: toFormValue(aiValue)
        Factory-->>AiAction: transformed value
        AiAction->>AiAction: $set(field, value)
    end

    AiAction->>User: success/partial/error notification
```

This is the direct path. With `->withPreview()` the result isn't written immediately — it's parked for the user to review, per the next diagram.

## Preview & Conversational Refinement

When `->withPreview()` (or `->conversational()`, which implies it) is set, the pipeline runs the AI call but **defers the write**: the structured result is stored on the Livewire component's `solarisPreviewData` and the action `halt()`s so the modal stays open. The user then accepts, refines, or cancels. This requires the [`InteractsWithSolarisPreview`](ai-action.md#preview) trait on the host Livewire component, which provides the methods the modal dispatches to.

```mermaid
sequenceDiagram
    participant User
    participant Action as AiAction
    participant AI as laravel/ai Provider
    participant LW as Livewire (InteractsWithSolarisPreview)
    participant Modal as Preview Modal

    User->>Action: clicks action button
    Action->>AI: run pipeline (compose prompt → structured response)
    AI-->>Action: structured JSON response
    Note over Action: withPreview(): don't write the form —<br/>store result + halt() to keep the modal open
    Action->>LW: set solarisPreviewData (displays, isConversational)
    LW->>Modal: render preview-modal / preview-conversational

    alt Accept
        User->>Modal: click Accept
        Modal->>LW: solarisAcceptPreview()
        LW->>Action: acceptPreview(data)
        Action->>LW: write values into form fields
        LW->>Modal: unmountAction() (close + clear)
    else Refine (conversational only)
        User->>Modal: type a follow-up message
        Modal->>LW: solarisRefinePreview(message)
        LW->>Action: refine(message, turnAttachments)
        Action->>AI: continue conversation + new message
        AI-->>Action: refined structured response
        Action->>LW: update solarisPreviewData + append turn
        LW->>Modal: re-render preview (loop)
    else Cancel
        User->>Modal: click Cancel
        Modal->>LW: solarisDiscardPreview()
        LW->>Modal: clear data + unmountAction()
    end
```

The refine loop re-runs the AI with the new message appended to the conversation (via `laravel/ai`'s `RemembersConversations`); each turn replaces the preview until the user accepts. See [Conversational Refinement](ai-action.md#conversational-refinement) for the requirements (migrations, authenticated user) and lifetime semantics.

## Component Hierarchy

```mermaid
classDiagram
    class ComponentFactory {
        <<interface>>
        +responseSchema(JsonSchema): Type
        +toFormValue(mixed): mixed
        +toPromptContext(mixed): mixed
    }

    class AbstractComponentFactory {
        <<abstract>>
        #component: Component
        #scope: Closure|null
        +make(Component, Closure|null): static
    }

    ComponentFactory <|.. AbstractComponentFactory
    AbstractComponentFactory <|-- SelectFactory
    AbstractComponentFactory <|-- TextFactory
    AbstractComponentFactory <|-- BooleanFactory
    AbstractComponentFactory <|-- CheckboxListFactory
    AbstractComponentFactory <|-- RichEditorFactory
    AbstractComponentFactory <|-- FileUploadFactory
    SelectFactory <|-- RadioFactory

    class PromptBuilder {
        <<interface>>
        +build(): string
        +defaultUserInput(): UserInput|null
    }

    class AbstractPromptBuilder {
        <<abstract>>
        #buildViewData(): array
        #resolveLocaleName(): string
    }

    PromptBuilder <|.. AbstractPromptBuilder
    AbstractPromptBuilder <|-- InlinePromptBuilder
    AbstractPromptBuilder <|-- ViewPromptBuilder
    AbstractPromptBuilder <|-- Preset

    Preset <|-- SummaryPreset
    Preset <|-- ClassificationPreset
    Preset <|-- TranslationPreset
    Preset <|-- GenerationPreset
```
