# Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag="filament-solaris-config"
```

This creates `config/filament-solaris.php` with all available options. The file is organised into semantic groups: `icons`, `locale`, `prompt_logging`, `ai`, `transcription`, `image_generation`, plus standalone top-level keys (`factories`, `max_options`, `default_tone`, `preset_providers`).

For per-panel overrides see [Per-Panel Configuration](../README.md#per-panel-configuration-plugin) — every key documented below has a matching plugin setter.

## Factory Map

Maps Filament component classes to their AI factory classes. The package auto-detects component types and uses the appropriate factory. You can add custom mappings here or via `FilamentSolaris::registerFactory()` in a service provider.

```php
'factories' => [
    Select::class => SelectFactory::class,
    Radio::class => RadioFactory::class,
    // Add your own...
    \App\Components\ColorPicker::class => \App\Factories\ColorFactory::class,
],
```

## Max Options

The threshold above which `SelectFactory` and `CheckboxListFactory` switch from a strict enum schema to free-text with fuzzy matching. Default: `100`.

```php
'max_options' => 100,
```

## Icons

Default icons for the Solaris action buttons. Each entry accepts a Heroicon name string or a `BackedEnum` that resolves to an icon name.

```php
'icons' => [
    'action' => Heroicon::OutlinedSparkles,
    'dictation' => Heroicon::OutlinedMicrophone,
    'image_generation' => Heroicon::OutlinedPhoto,
    'conversation_send' => Heroicon::OutlinedPaperAirplane,
    'conversation_attachment' => Heroicon::OutlinedPaperClip,
],
```

## Locale

`default` overrides the locale used in prompts. When `null`, `app()->getLocale()` is used. Individual actions can override this with `->locale()`.

`supported` lists the locales available for the `TranslationPreset` language selector.

**Fallback chain** (first non-null wins):
1. Runtime: `FilamentSolaris::setLocales([...])` in a service provider
2. `locale.supported` config key
3. `config('app.supported_locales')`
4. `[config('app.locale')]` (single-locale fallback)

Accepts a flat array or key-value array:

```php
'locale' => [
    'default' => null,

    // Flat — display names resolved automatically via intl extension
    'supported' => ['en', 'nl', 'fr'],

    // Or key-value — display names used as-is
    // 'supported' => ['en' => 'English', 'nl' => 'Dutch', 'fr' => 'French'],
],
```

## Default Tone

The default tone used by presets like `SummaryPreset` and `GenerationPreset`. Individual presets can override this with `->tone()`.

```php
'default_tone' => 'neutral',
```

## Prompt Logging

Log the composed prompt, JSON schema, and per-call `Usage` via Laravel's logger. Useful for debugging during development. Should be disabled in production.

```php
'prompt_logging' => [
    'enabled' => (bool) env('FILAMENT_SOLARIS_PROMPT_LOGGING', false),
    'channel' => null, // null = default Laravel log channel
],
```

Custom loggers can also be registered via `FilamentSolaris::registerLogger()` in a service provider.

## AI Defaults

Defaults for text-generation AI calls. When a value is `null`, the `laravel/ai` default (`config('ai.default')`) is used — or the agent's PHP attributes for the generation options.

```php
'ai' => [
    'default_provider' => null,
    'default_model' => null,
    'default_timeout' => null,       // seconds; null = laravel/ai default (60s)
    'default_temperature' => null,   // float, e.g. 0.7
    'default_max_tokens' => null,    // int, hard cap on output tokens
    'default_max_steps' => null,     // int, max tool-call steps
    'default_top_p' => null,         // float, nucleus sampling
],
```

Supports all provider shapes accepted by `laravel/ai`:

```php
// Single provider string
'ai' => ['default_provider' => 'openai'],

// Provider + model
'ai' => [
    'default_provider' => 'anthropic',
    'default_model' => 'claude-sonnet-4-5-20250514',
],

// Failover array — tries providers in order
'ai' => [
    'default_provider' => ['openai' => 'gpt-4o', 'anthropic' => 'claude-sonnet-4-5-20250514'],
],
```

### Resolution Chain

Provider, model, and timeout are each resolved with a priority chain (highest wins):

**Provider & model:**
1. Action-level `->provider()`
2. Preset-level `->provider()` on the preset object
3. Config `preset_providers[PresetClass]` provider/model
4. Config `ai.default_provider` / `ai.default_model`
5. laravel/ai default (`config('ai.default')`)

**Timeout:**
1. Action-level `->timeout()`
2. Config `preset_providers[PresetClass]` timeout
3. Config `ai.default_timeout`
4. laravel/ai default (60s)

**Generation options (`temperature`, `max_tokens`, `max_steps`, `top_p`):**
1. Action-level `->temperature()` / `->maxTokens()` / `->maxSteps()` / `->topP()`
2. Preset-level method on the preset object (e.g. `SummaryPreset::make()->temperature(0.3)`)
3. Config `preset_providers[PresetClass]` `temperature` / `max_tokens` / `max_steps` / `top_p`
4. Config `ai.default_temperature` / `ai.default_max_tokens` / `ai.default_max_steps` / `ai.default_top_p`
5. Agent PHP attributes (`#[Temperature]`, `#[MaxTokens]`, `#[MaxSteps]`, `#[TopP]`) — read by laravel/ai
6. Provider default

## Transcription Defaults

Defaults for the transcription step in `DictationFieldAction` — separate from the AI-processing provider.

```php
'transcription' => [
    'default_provider' => null,
    'default_model' => null,
    'default_timeout' => null, // seconds
],
```

Actions can override these with `->transcriptionProvider()` and `->transcriptionTimeout()`:

```php
DictationFieldAction::make('voice')
    ->transcriptionProvider('openai', 'whisper-1')  // transcription provider
    ->transcriptionTimeout(30)                       // transcription timeout
    ->provider('anthropic')                          // AI processing provider
    ->timeout(120)                                   // AI processing timeout
```

## Image Generation Defaults

Defaults for `ImageGenerationAction`. When `null`, the `laravel/ai` default for images (`config('ai.default_for_images')`) is used. Only providers that implement `ImageProvider` are supported: OpenAI, Gemini, xAI.

```php
'image_generation' => [
    'default_provider' => null,
    'default_model' => null,
    'default_timeout' => null,
    'default_size' => null,           // '1:1', '3:2', '2:3'
    'default_quality' => null,        // 'low', 'medium', 'high'
    'default_disk' => null,           // Storage disk (null = default filesystem disk)
    'default_directory' => 'ai-images',
    'default_visibility' => null,     // 'public' or null
],
```

The `disk`, `directory`, and `visibility` settings are only used as fallback when the target field is not a `FileUpload` component (e.g. `TextInput`). For `FileUpload` and `SpatieMediaLibraryFileUpload`, the image is stored as a Livewire temporary upload and the component's own disk/directory configuration is used on save.

## Per-Preset Provider Overrides

Route specific preset types to different providers, models, and timeouts. Useful for sending cheap tasks (classification) to cheaper/faster models while using capable models for complex tasks (generation).

```php
'preset_providers' => [
    \Statikbe\FilamentSolaris\Prompts\Presets\ClassificationPreset::class => [
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'timeout' => 30,        // fast model, short timeout
        'temperature' => 0.0,   // deterministic for classification
        'max_tokens' => 256,    // classifications are short
    ],
    \Statikbe\FilamentSolaris\Prompts\Presets\SummaryPreset::class => [
        'provider' => ['openai' => 'gpt-4o', 'anthropic'], // failover
        'timeout' => 120,
        'temperature' => 0.5,
        'top_p' => 0.95,
    ],
],
```

All keys are optional: `provider`, `model`, `timeout`, `temperature`, `max_tokens`, `max_steps`, `top_p`. Each is overridden by the matching action-level setter (`->provider()`, `->timeout()`, `->temperature()`, etc.) or by a preset-level setter on the preset object.
