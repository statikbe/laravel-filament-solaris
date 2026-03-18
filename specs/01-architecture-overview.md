# 01 — Architecture Overview

## Summary

filament-solaris is a Filament v4 & v5 plugin that lets developers add AI-powered actions to forms. The package reads data from form fields, sends it to an AI agent via laravel-ai, and writes structured responses back to target form fields. It auto-detects field types and handles data transformation both ways.

## Core Concepts

### Action
The Filament UI trigger. An `AiAction` extends Filament's `Action` class and can be placed on forms. It orchestrates the entire flow: collecting source data, resolving factories, building the prompt, calling the AI, and writing results back.

### ComponentFactory
A data transformer tied to a specific Filament form component type. Each factory knows how to:
- Generate a JSON schema fragment describing valid AI output for its component type
- Transform the AI response value back into a valid Filament form state value
- Transform form state into prompt-friendly context

Factories are auto-resolved based on the target field's component type. Developers never need to create factories for standard use cases.

### PromptBuilder
Composes the final prompt string sent to the AI. Receives the developer's instruction, source data, factory instances, optional record, locale, and user input. Ships with three implementations:
- `InlinePromptBuilder` — wraps a plain string instruction in a base template
- `ViewPromptBuilder` — renders a custom Blade view
- `Preset` (abstract) — self-contained prompt builder with typed configuration

### Preset
A first-class object representing a common AI task (summarize, classify, translate, generate). Has typed setters for configuration, provides its own Blade template, and implements the PromptBuilder interface. Presets can also declare default user input fields they need at runtime.

### UserInput
Defines what the end user can configure at runtime before the AI executes. Opens a Filament modal with form fields. The user's input is passed to the PromptBuilder as additional context. Designed to evolve into a chat interface in v2.

### ComponentFactoryResolver
Walks the Filament form component tree, finds the target field's component, and instantiates the appropriate factory. Maintains a configurable map of component class → factory class.

## Data Flow

```
Developer configures AiAction
    │
    │  ->sourceFields(['title', 'body'])
    │  ->targetField('category_id')
    │  ->preset(ClassifyPreset::make())
    │  ->userInput(UserInput::make()->placeholder('...'))
    │
End user clicks the action button
    │
    ▼
[If UserInput configured] Modal opens → user fills in instructions → submits
    │
    ▼
AiAction::execute()
    │
    ├── 1. Collect source field values via Filament's Get
    │      Apply sourceScope closures if configured
    │
    ├── 2. Resolve ComponentFactory for each target field
    │      ComponentFactoryResolver walks the form component tree
    │      Finds component class → maps to factory class
    │      Passes targetScope closure if configured
    │
    ├── 3. Build prompt via PromptBuilder
    │      PromptBuilder receives:
    │        - instruction (string or Blade view)
    │        - sourceData (array)
    │        - factories (keyed by field name)
    │        - record (nullable Model, available on edit pages)
    │        - locale (from app or override)
    │        - userInput (array from modal form)
    │      PromptBuilder iterates factories to get per-field JSON schemas
    │      Renders final prompt string
    │
    ├── 4. Call AI via laravel-ai
    │      Resolve provider/model (action → preset → config → laravel/ai default)
    │      Send composed prompt with resolved provider/model
    │      Receive JSON response
    │
    ├── 5. Transform response per target field
    │      For each target field:
    │        factory->toFormValue(responseData[fieldName])
    │      Validates and maps AI output to valid form state
    │
    ├── 6. Handle results
    │      [If withPreview] Show modal with results, user accepts/discards
    │      [If direct-fill] Set values via Filament's Set mechanism
    │      [If partial failure] Fill valid fields, warn about failed ones
    │
    ▼
Form state updated, user continues editing
```

## UX Modes

### Direct Fill (default)
- Loading spinner on button during AI call
- Fields update immediately on completion
- Success notification shown

### Preview
- Enabled with `->withPreview()`
- After AI responds, a confirmation modal shows the proposed values
- User can accept (applies all values) or discard
- Target fields shown in appropriate display format (factory helps render preview)

## Error Handling Strategy

- If AI returns valid data for some fields but garbage for others:
  fill the valid fields and show a warning notification listing the failed fields
- If the entire AI call fails (timeout, API error):
  show an error notification, don't modify any fields
- If a target field's factory can't map the AI response (fuzzy match fails):
  treat that field as failed, include in the warning

## Versioning Roadmap

### MVP (v0.1)
- AiAction with sourceFields, targetField, sourceScope, targetScope
- ComponentFactory interface + 8 implementations (Select, Radio, Text, CheckboxList, Boolean, RichEditor, Markdown, Tags)
- ComponentFactoryResolver with auto-detection
- PromptBuilder interface + InlinePromptBuilder + ViewPromptBuilder
- 4 Presets (Summarize, Classify, Translate, Generate) as typed classes
- UserInput for runtime end-user configuration
- Direct-fill and preview UX modes
- AiAction::fake() for testing
- Partial error handling (fill valid, warn failed)
- Provider/model selection per action, preset, and config (with failover arrays)

### v2 (planned)
- Chat modal: UserInput evolves into multi-turn conversation
- Streaming responses
- Bulk actions (batch processing multiple records)
- Image generation field

### v3 (future)
- Custom agent workflows (multi-step AI pipelines)
- Cost tracking and token budgets
