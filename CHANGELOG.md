# Changelog

All notable changes to `laravel-filament-solaris` will be documented in this file.

## [0.1.0] - 2026-05-14

Initial public release. Solaris ships as `0.x` while [`laravel/ai`](https://github.com/laravel/ai) is pre-1.0 — see [Versioning](README.md#versioning) for the coupling.

### Added

- **`AiAction`** — Filament `Action` that reads source fields, sends them to an AI provider via `laravel/ai`, and writes structured responses back into one or more target fields.
- **`ImageGenerationAction`** — generates images via the `laravel/ai` Image API and writes them to a `FileUpload` (or `SpatieMediaLibraryFileUpload`) field; supports OpenAI `gpt-image-1`, DALL·E, and any provider laravel/ai exposes.
- **`DictationFieldAction`** — modal-based audio recording + transcription, attached to any Filament `Field` via `->hintAction(...)` (universal) or `->suffixAction(...)` (TextInput). Auto-resolves the host field as the write-back target. Optional AI post-processing of the transcript via `->preset()` / `->prompt()`. The recording/transcription mechanics live in a `HandlesDictation` trait so future variants (e.g. a `DictationToolbarAction` for `RichEditor` toolbar buttons) can reuse them.
- **Component factories** — `Select`, `Radio`, `TextInput`, `Textarea`, `CheckboxList`, `Toggle`, `RichEditor`, `FileUpload`, `SpatieMediaLibraryFileUpload`. Custom factories registerable via facade or config.
- **Tunable option matching** — the Select/CheckboxList resolution chain's fuzzy step now uses a **length-relative** Levenshtein threshold (`option_matching.fuzzy_threshold`, default `0.25`) instead of an absolute `≤ 3`, with a `fuzzy_min_length` (default `4`) floor so short values can't fuzzy-flip ("cat" → "car"). Fuzzy can be disabled globally or per field (`->targetFuzzyMatching($field, false)`, `->targetFuzzyThreshold($field, 0.15)`, plus plugin `->optionFuzzyMatching()/...`). Every inexact match (substring or fuzzy) dispatches `SolarisOptionMatched` (field, ai value, matched key/label, strategy, distance) so apps can detect misclassification in production. **Behavior change:** some matches the old absolute threshold accepted now fall through unmatched — the correct direction, but worth noting if you relied on loose short-string matching. See [Option Matching](documentation/factories.md#option-matching).
- **Presets** — `SummaryPreset`, `ClassificationPreset`, `TranslationPreset`, `GenerationPreset` for common prompt patterns.
- **Prompt builders** — inline strings, Blade views, or custom `PromptBuilder` classes.
- **Preview modal** — show AI output before applying via `->withPreview()` + `InteractsWithSolarisPreview` trait on the Livewire component.
- **Conversational refinement** — `->conversational()` enables a chat-style refinement loop in the preview modal, backed by `laravel/ai`'s `RemembersConversations` when an authenticated user is present.
- **Attachments** — three input channels (parent-form `FileUpload`, `UserInput` modal, programmatic Closure) shared between text and image actions. Auto-detects `Image` / `Audio` / `Document` by MIME with extension fallback.
- **Generation options** — `temperature()`, `maxTokens()`, `maxSteps()`, `topP()` fluent setters across action / preset / config layers.
- **Provider / model / timeout overrides** — per-action, per-preset, and package-wide config defaults; separate defaults for transcription and image generation.
- **Locale support** — per-action `->locale()` override; configurable supported-locale list for the translation preset.
- **Testing fakes** — `AiAction::fake()`, `DictationFieldAction::fake()`, `ImageGenerationAction::fake()` with assertion helpers (`assertCalled`, `assertCalledWith`, `assertCalledTimes`).
- **Abstract `SolarisAction` base** — form-agnostic AI core (provider/timeout/executeAiCall/preview state) so future non-form actions can build on the same foundation without inheriting form-field assumptions.
- **Usage-tracking events** — `SolarisResponseReceived` and `SolarisResponseFailed` fire after every AI call (text, image, transcription) with the laravel/ai `Usage` payload plus Solaris context (action name, provider, model, duration, user, Livewire component). No built-in persistence; apps wire a listener. The fakes dispatch synthetic events so listeners can be tested without a real provider. Sample listener in [Usage Tracking](documentation/usage-tracking.md).
- **`FilamentSolarisPlugin`** — per-panel configuration via the idiomatic `$panel->plugin(...)` pattern. Every Tier-1/2 config key (provider, model, timeout, text-gen options, transcription, image generation, logging, locales, icons, tone, preset overrides) has a fluent setter. Panel-level visibility gate via `->visible(bool|Closure)` / `->disabled()` is registered as `->hidden(...)` on every Solaris action — hard-AND with the action's own visibility so consumers can't accidentally bypass it. Falls through to `config/filament-solaris.php` outside panel context (CLI, queued jobs). See [Per-Panel Configuration](documentation/configuration.md#per-panel-configuration-plugin).

### Config-file shape

The published `config/filament-solaris.php` groups related keys into nested arrays — `icons`, `locale`, `prompt_logging`, `ai`, `transcription`, `image_generation`, `option_matching` — plus standalone `factories`, `max_options`, `default_tone`, `preset_providers`. If you held onto a previous unreleased copy of the config, the dot-notation mapping is:

| Old (flat) | New (nested) |
|---|---|
| `action_icon`, `dictation_icon`, `image_generation_icon`, `conversation_send_icon`, `conversation_attachment_icon` | `icons.action`, `icons.dictation`, `icons.image_generation`, `icons.conversation_send`, `icons.conversation_attachment` |
| `default_locale`, `supported_locales` | `locale.default`, `locale.supported` |
| `prompt_logging_enabled`, `prompt_logging_channel` | `prompt_logging.enabled`, `prompt_logging.channel` |
| `default_provider`, `default_model`, `default_timeout`, `default_temperature`, `default_max_tokens`, `default_max_steps`, `default_top_p` | `ai.default_provider`, `ai.default_model`, `ai.default_timeout`, `ai.default_temperature`, `ai.default_max_tokens`, `ai.default_max_steps`, `ai.default_top_p` |
| `default_transcription_provider`, `default_transcription_model`, `default_transcription_timeout` | `transcription.default_provider`, `transcription.default_model`, `transcription.default_timeout` |
| `default_image_*` | `image_generation.default_*` (drops the `image_` prefix on the leaf) |

Public PHP setters (`->defaultProvider()`, `->actionIcon()`, etc.) are unchanged.

### Notes

- Requires `php ^8.3`, `filament/filament ^4.2 || ^5.0`, `laravel/ai ^0.6`.
- Suggests `ext-intl` (for human-readable locale display names in `TranslationPreset`; without it, raw locale codes are shown unless a translation entry is published).
- Tagged `0.1.0` rather than `1.0.0` because `laravel/ai` is still pre-1.0; see [Versioning](README.md#versioning).
