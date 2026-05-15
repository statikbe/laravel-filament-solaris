# Changelog

All notable changes to `laravel-filament-solaris` will be documented in this file.

## [0.1.0] - 2026-05-14

Initial public release. Solaris ships as `0.x` while [`laravel/ai`](https://github.com/laravel/ai) is pre-1.0 — see [Versioning](README.md#versioning) for the coupling.

### Added

- **`AiAction`** — Filament `Action` that reads source fields, sends them to an AI provider via `laravel/ai`, and writes structured responses back into one or more target fields.
- **`ImageGenerationAction`** — generates images via the `laravel/ai` Image API and writes them to a `FileUpload` (or `SpatieMediaLibraryFileUpload`) field; supports OpenAI `gpt-image-1`, DALL·E, and any provider laravel/ai exposes.
- **`DictationAction`** — modal-based audio recording + transcription with optional AI post-processing of the transcript.
- **Component factories** — `Select`, `Radio`, `TextInput`, `Textarea`, `CheckboxList`, `Toggle`, `RichEditor`, `FileUpload`, `SpatieMediaLibraryFileUpload`. Custom factories registerable via facade or config.
- **Presets** — `SummaryPreset`, `ClassificationPreset`, `TranslationPreset`, `GenerationPreset` for common prompt patterns.
- **Prompt builders** — inline strings, Blade views, or custom `PromptBuilder` classes.
- **Preview modal** — show AI output before applying via `->withPreview()` + `InteractsWithSolarisPreview` trait on the Livewire component.
- **Conversational refinement** — `->conversational()` enables a chat-style refinement loop in the preview modal, backed by `laravel/ai`'s `RemembersConversations` when an authenticated user is present.
- **Attachments** — three input channels (parent-form `FileUpload`, `UserInput` modal, programmatic Closure) shared between text and image actions. Auto-detects `Image` / `Audio` / `Document` by MIME with extension fallback.
- **Generation options** — `temperature()`, `maxTokens()`, `maxSteps()`, `topP()` fluent setters across action / preset / config layers.
- **Provider / model / timeout overrides** — per-action, per-preset, and package-wide config defaults; separate defaults for transcription and image generation.
- **Locale support** — per-action `->locale()` override; configurable supported-locale list for the translation preset.
- **Testing fakes** — `AiAction::fake()`, `DictationAction::fake()`, `ImageGenerationAction::fake()` with assertion helpers (`assertCalled`, `assertCalledWith`, `assertCalledTimes`).
- **Abstract `SolarisAction` base** — form-agnostic AI core (provider/timeout/executeAiCall/preview state) so future non-form actions can build on the same foundation without inheriting form-field assumptions.
- **Usage-tracking events** — `SolarisResponseReceived` and `SolarisResponseFailed` fire after every AI call (text, image, transcription) with the laravel/ai `Usage` payload plus Solaris context (action name, provider, model, duration, user, Livewire component). No built-in persistence; apps wire a listener. The fakes dispatch synthetic events so listeners can be tested without a real provider. Sample listener in [Usage Tracking](README.md#usage-tracking).

### Notes

- Requires `php ^8.3`, `filament/filament ^4.2 || ^5.0`, `laravel/ai ^0.6`, `ext-intl`.
- Tagged `0.1.0` rather than `1.0.0` because `laravel/ai` is still pre-1.0; see [Versioning](README.md#versioning).
