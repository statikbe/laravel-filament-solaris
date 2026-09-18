# Architecture

[← Back to README](../README.md)

## Execution Pipeline

```mermaid
sequenceDiagram
    participant User
    participant AiFormAction
    participant PromptBuilder
    participant SolarisAgent
    participant AI as laravel/ai Provider
    participant Factory as ComponentFactory

    User->>AiFormAction: clicks action button
    Note over AiFormAction: if UserInput configured
    AiFormAction->>User: show modal form
    User->>AiFormAction: submit user input

    AiFormAction->>AiFormAction: validate config
    AiFormAction->>AiFormAction: collect source field values
    AiFormAction->>AiFormAction: resolve target factories

    AiFormAction->>PromptBuilder: build(instruction, sourceData, factories, record, locale, userInput)
    PromptBuilder-->>AiFormAction: composed prompt string

    AiFormAction->>SolarisAgent: configure(prompt, factories)
    SolarisAgent->>AI: prompt() with JSON schema
    AI-->>SolarisAgent: structured JSON response

    loop for each target field
        AiFormAction->>Factory: toFormValue(aiValue)
        Factory-->>AiFormAction: transformed value
        AiFormAction->>AiFormAction: $set(field, value)
    end

    AiFormAction->>User: success/partial/error notification
```

This is the direct path. With `->withPreview()` the result isn't written immediately — it's parked for the user to review, per the next diagram.

## Preview & Conversational Refinement

When `->withPreview()` (or `->conversational()`, which implies it) is set, the pipeline runs the AI call but **defers the write**: the structured result is stored on the Livewire component's `solarisPreviewData` and the action `halt()`s so the modal stays open. The user then accepts, refines, or cancels. This requires the [`InteractsWithSolarisPreview`](ai-form-action.md#preview) trait on the host Livewire component, which provides the methods the modal dispatches to.

```mermaid
sequenceDiagram
    participant User
    participant Action as AiFormAction
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

The refine loop re-runs the AI with the new message appended to the conversation (via `laravel/ai`'s `RemembersConversations`); each turn replaces the preview until the user accepts. See [Conversational Refinement](ai-form-action.md#conversational-refinement) for the requirements (migrations, authenticated user) and lifetime semantics.

## The Generation Engine

`AiGenerator` is the headless core: a fluent builder with two terminals. Everything below
it is Filament-free and runs identically from a controller, a listener, an artisan command
or a queue worker.

```mermaid
classDiagram
    direction LR

    class AiGenerator {
        +prompt(string|View|Closure)
        +schema(Closure) / forModel(class)
        +sourceRecords(rows) / count(n)
        +createRecords() / updateRecords()
        +batchSize(n) / promptContextColumns()
        +sanitize() / sanitizeField()
        +trackBatchRuns() / onCompletion()
        +runInline() GenerationResult|BatchSummary
        +runQueued() SolarisBatchRun
    }

    class InlineRunner
    class QueuedRunner
    class ProcessChunkJob {
        tries = 1
    }
    class FinalizeRun
    class BatchProcessor {
        reconcile + persist + collect
    }
    class RecordWriter {
        create | update
    }
    class RecordsSchemaBuilder
    class BatchPromptBuilder
    class SanitizerExecutor
    class SolarisAgent
    class BatchSink {
        <<interface>>
    }
    class DatabaseBatchSink
    class InMemoryBatchSink
    class CompositeBatchSink
    class SolarisBatchRun
    class CompletionHandlerRunner
    class BatchCompletionHandler {
        <<interface>>
    }

    AiGenerator ..> InlineRunner : runInline
    AiGenerator ..> QueuedRunner : runQueued
    AiGenerator ..> SolarisAgent : the AI call
    AiGenerator ..> RecordsSchemaBuilder : schema from model
    AiGenerator ..> BatchPromptBuilder : chunk prompt
    AiGenerator ..> SolarisBatchRun : creates + tracks
    InlineRunner ..> BatchProcessor : in-request
    InlineRunner ..> CompletionHandlerRunner
    QueuedRunner ..> ProcessChunkJob : dispatches chunks
    QueuedRunner ..> FinalizeRun : on batch finally
    ProcessChunkJob ..> BatchProcessor : on the worker
    FinalizeRun ..> CompletionHandlerRunner
    BatchProcessor ..> RecordWriter : persist callback
    BatchProcessor ..> BatchSink : record outcome
    RecordWriter ..> SanitizerExecutor : before write
    BatchSink <|.. DatabaseBatchSink
    BatchSink <|.. InMemoryBatchSink
    BatchSink <|.. CompositeBatchSink
    DatabaseBatchSink ..> SolarisBatchRun
    CompletionHandlerRunner ..> BatchCompletionHandler
```

Both runners reach the same `BatchProcessor` and `CompletionHandlerRunner` — the queued
path simply goes one hop further out, through `ProcessChunkJob` and `FinalizeRun`.

## Inline vs Queued

The inline and queued paths are not two implementations. They share the processor, the
writer, the schema builder and the prompt builder; `runQueued()` only moves the agent call
onto a worker and wraps the chunks in a `Bus::batch`.

```mermaid
flowchart LR
    G["AiGenerator<br/>fluent config"]

    G -- "runInline()" --> IR[InlineRunner]
    G -- "runQueued()" --> QR[QueuedRunner]

    QR -- "Bus::batch" --> PCJ["ProcessChunkJob<br/>tries = 1"]

    subgraph CORE ["shared core"]
        BP[BatchProcessor] --> RW["RecordWriter<br/>+ Sanitizers"]
    end

    IR -- "in-request" --> BP
    PCJ -- "on the worker" --> BP

    RW --> EL["Eloquent<br/>create | update"]

    QR -. "batch finally" .-> FR[FinalizeRun]
    FR --> EV[SolarisBatchCompleted] --> CH[completion handlers]
```

The only structural difference is the boundary the AI call sits behind. Queued mode always
persists a `SolarisBatchRun`, because the run row is the one place per-chunk outcomes can
aggregate.

## The Action Layer

Every action extends `SolarisAction`, which extends Filament's `Action` and carries the
capabilities all of them share. Below that, the traits split along *what the action writes
to*.

```mermaid
classDiagram
    direction TB

    class Action {
        <<Filament>>
    }
    class SolarisAction {
        <<abstract>>
        +provider() / timeout()
        #executeAiCall()
    }
    class HasAttachments {
        <<trait>>
    }
    class HasConversational {
        <<trait>>
    }
    class HasPreviewModal {
        <<trait>>
    }

    class AiFormAction {
        source fields to target fields
    }
    class AiGenerateAction {
        structured output to records
    }
    class ImageGenerationAction
    class DictationFieldAction
    class DictationToolbarAction

    class HasPromptPipeline {
        <<trait>>
        builds its own SolarisAgent
    }
    class HasFormPipeline {
        <<trait>>
    }
    class HasSourceFields {
        <<trait>>
    }
    class HasQueuedExecution {
        <<trait>>
    }
    class HasUserInput {
        <<trait>>
    }
    class HasGenerationOptions {
        <<trait>>
    }
    class HandlesDictation {
        <<trait>>
    }
    class AiGenerator

    Action <|-- SolarisAction
    SolarisAction ..> HasAttachments
    SolarisAction ..> HasConversational
    SolarisAction ..> HasPreviewModal

    SolarisAction <|-- AiFormAction
    SolarisAction <|-- AiGenerateAction
    SolarisAction <|-- ImageGenerationAction
    SolarisAction <|-- DictationFieldAction
    SolarisAction <|-- DictationToolbarAction

    AiFormAction ..> HasPromptPipeline
    AiFormAction ..> HasFormPipeline
    AiFormAction ..> HasSourceFields
    DictationFieldAction ..> HasPromptPipeline
    DictationFieldAction ..> HandlesDictation
    DictationToolbarAction ..> HandlesDictation
    ImageGenerationAction ..> HasFormPipeline
    ImageGenerationAction ..> HasSourceFields
    AiGenerateAction ..> HasQueuedExecution
    AiGenerateAction ..> HasUserInput
    AiGenerateAction ..> HasGenerationOptions
    AiGenerateAction ..> AiGenerator : delegates the whole engine
    HasPromptPipeline ..> HasGenerationOptions
```

The asymmetry is deliberate, up to a point: `AiGenerateAction` hands the entire engine to
the service, while `AiFormAction` still runs its own agent inside `HasPromptPipeline`.
Form write-back, preview and conversational refinement stay Filament-side either way.

## Component Hierarchy

Factories translate between a Filament component and an AI-shaped value. `responseSchema()`
contributes one property to the response schema; `toFormValue()` maps the returned value
back to form state; the write itself is done by the caller through Filament's `Set`
utility.

```mermaid
classDiagram
    direction LR

    class ComponentFactoryContract {
        <<interface>>
        +responseSchema(JsonSchemaTypeFactory): Type
        +toFormValue(mixed): mixed
        +toFormValueFromFile(string, string): mixed
        +toPromptContext(mixed): mixed
        +toPreviewDisplay(mixed): array
        +afterStateHydration(mixed, LivewireComponent): void
    }

    class ComponentFactory {
        <<abstract>>
        #component: Component
        #scope: Closure|null
        +make(Component, Closure|null): static
        +hint(string|null): static
        +getComponent() / getScope()
        +readState() mixed
        +toAttachments() array
    }

    class SelectFactory
    class RadioFactory
    class CheckboxListFactory
    class TextFactory
    class BooleanFactory
    class RichEditorFactory
    class MarkdownFactory
    class TagsFactory
    class FileUploadFactory
    class SpatieMediaLibraryFileUploadFactory
    class ComponentFactoryResolver

    ComponentFactoryContract <|.. ComponentFactory
    ComponentFactory <|-- SelectFactory
    SelectFactory <|-- RadioFactory
    ComponentFactory <|-- CheckboxListFactory
    ComponentFactory <|-- TextFactory
    ComponentFactory <|-- BooleanFactory
    ComponentFactory <|-- RichEditorFactory
    ComponentFactory <|-- MarkdownFactory
    ComponentFactory <|-- TagsFactory
    ComponentFactory <|-- FileUploadFactory
    FileUploadFactory <|-- SpatieMediaLibraryFileUploadFactory
    ComponentFactoryResolver ..> ComponentFactory : resolves by component
```

> The interface is `Statikbe\FilamentSolaris\Contracts\ComponentFactory`; the abstract base
> is `Statikbe\FilamentSolaris\Factories\ComponentFactory`. The base defaults four of the
> six methods, so a custom factory normally implements only `responseSchema()`,
> `toFormValue()` and `toPromptContext()`. See [Factories](factories.md).

## Prompt Builders & Presets

```mermaid
classDiagram
    direction LR

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

    class InlinePromptBuilder
    class ViewPromptBuilder
    class Preset {
        <<abstract>>
    }
    class SummaryPreset
    class ClassificationPreset
    class TranslationPreset
    class GenerationPreset

    PromptBuilder <|.. AbstractPromptBuilder
    AbstractPromptBuilder <|-- InlinePromptBuilder
    AbstractPromptBuilder <|-- ViewPromptBuilder
    AbstractPromptBuilder <|-- Preset

    Preset <|-- SummaryPreset
    Preset <|-- ClassificationPreset
    Preset <|-- TranslationPreset
    Preset <|-- GenerationPreset
```

Presets *are* prompt builders — that inheritance is what makes them reusable wherever a
prompt is accepted. See [Presets](presets.md) and [Prompt Builders](prompt-builders.md).

## Sanitizers

Sanitizers clean a string on its way to storage. Two roles that are easy to conflate:
`CompositeSanitizer` is a **per-value pipeline** (trim, then strip tags, on one string),
while `SanitizerExecutor` is a **per-field router** (a default plus a per-field map, run
across a whole attribute map). A field's sanitizer may itself be a composite.

```mermaid
classDiagram
    direction LR

    class Sanitizer {
        <<interface>>
        +sanitize(string): string
    }
    class StripTagsSanitizer
    class TrimSanitizer
    class HtmlPurifierSanitizer
    class CallableSanitizer
    class CompositeSanitizer {
        per-value pipeline
    }
    class SanitizerExecutor {
        per-field router
    }

    Sanitizer <|.. StripTagsSanitizer
    Sanitizer <|.. TrimSanitizer
    Sanitizer <|.. HtmlPurifierSanitizer
    Sanitizer <|.. CallableSanitizer
    Sanitizer <|.. CompositeSanitizer
    SanitizerExecutor ..> Sanitizer : default + per-field map
```

See [Security](security.md) for when each applies.
