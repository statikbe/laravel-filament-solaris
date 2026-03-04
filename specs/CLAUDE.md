# Package: laravel-filament-solaris

## Overview

A Laravel Filament v4 & v5 plugin that provides actions and components to add AI-powered features to Filament forms using the laravel-ai library. Developers can add AI action buttons that read from form fields, send prompts to an AI agent, and write structured responses back to form fields — with automatic field type detection and data transformation.

Like the ocean of Solaris, it observes your data and creates from it.

## Principles

- This is a Filament v4 & v5 plugin package, follow Filament's conventions for actions, components, and service providers
- Use `laravel/ai` for all LLM interactions — never call AI APIs directly
- Prefer composition over inheritance
- Follow Laravel's fluent API patterns (method chaining, `make()` static constructors)
- Every public method must have a docblock
- This is a package that will be used by developers, so Developer Experience (DX) is very important. The SDK should feel fluent and like laravel.
- Write Pest tests, not PHPUnit
- Keep the developer experience simple: 80% of use cases should work with just `sourceFields()`, `targetField()`, `prompt()` — no custom classes needed
- The package auto-detects Filament component types and maps them to appropriate factories
- Prompt composition is always handled by a PromptBuilder — never concatenate strings in the action

## Tech Stack

- PHP 8.2+
- Laravel 11.x / 12.x
- Filament v4 & v5
- laravel/ai (check latest version on Packagist)
- Pest for testing

## Package Structure

```
src/
├── Actions/
│   └── AiAction.php
├── Concerns/
│   ├── HasSourceFields.php
│   ├── HasTargetFields.php
│   └── HasUserInput.php
├── Contracts/
│   ├── PromptBuilder.php
│   └── ComponentFactory.php
├── Factories/
│   ├── SelectFactory.php
│   ├── TextFactory.php
│   ├── CheckboxListFactory.php
│   ├── BooleanFactory.php
│   └── RadioFactory.php
├── Presets/
│   ├── Preset.php                 (abstract)
│   ├── SummarizePreset.php
│   ├── ClassifyPreset.php
│   ├── TranslatePreset.php
│   └── GeneratePreset.php
├── Support/
│   ├── ComponentFactoryResolver.php
│   ├── InlinePromptBuilder.php
│   ├── ViewPromptBuilder.php
│   └── UserInput.php
├── Testing/
│   └── AiActionFake.php
└── FilamentSolarisServiceProvider.php

resources/
├── views/
│   └── prompts/
│       ├── base-wrapper.blade.php
│       ├── summarize.blade.php
│       ├── classify.blade.php
│       ├── translate.blade.php
│       └── generate.blade.php

config/
└── filament-solaris.php
```

## Implementation Order

Implement specs in numerical order. Dependencies:

1. `01-architecture-overview.md` — read first for context, no code
2. `02-factory-contracts.md` — abstract base, implement first
3. `03-select-factory.md` through `06-boolean-factory.md` — concrete factories, can be parallel
4. `07-action-traits.md` — depends on 02
5. `08-prompt-pipeline.md` — depends on 02
6. `09-presets.md` — depends on 08
7. `10-user-input.md` — depends on 08
8. `11-ai-action.md` — depends on all above, this is the integration point
9. `12-testing.md` — the fake/mock helper, implement alongside or after 11

## Conventions

- All classes in the `Statikbe\FilamentSolaris` namespace
- Config file published as `filament-solaris.php`
- Views published under `filament-solaris` namespace
- Follow Filament's pattern of `make()` static constructors on all user-facing classes
- Use PHP 8.2 features: readonly properties, enums, named arguments where appropriate
- Blade prompt templates use `{{ }}` for escaped output, `{!! !!}` only for JSON schema blocks

## Testing Approach

- Unit tests for each factory (input → schema, response → form value)
- Unit tests for each preset (configuration → rendered prompt content)
- Unit tests for PromptBuilder implementations
- Unit tests for ComponentFactoryResolver
- Feature tests for AiAction using the fake helper
- No external API calls in any test — use AiAction::fake() exclusively
