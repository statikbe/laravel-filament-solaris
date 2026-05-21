# Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag="filament-solaris-config"
```

This creates `config/filament-solaris.php` with all available options. The file is organised into semantic groups: `icons`, `locale`, `prompt_logging`, `ai`, `transcription`, `image_generation`, `option_matching`, plus standalone top-level keys (`factories`, `max_options`, `default_tone`, `preset_providers`).

For per-panel overrides see [Per-Panel Configuration](#per-panel-configuration-plugin) — every key documented below has a matching plugin setter.

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

## Option Matching

Tunes how free-text AI answers are resolved back to Select/CheckboxList option keys. See [Component Factories → Option Matching](factories.md#option-matching) for behaviour and the per-field/per-panel overrides.

```php
'option_matching' => [
    'fuzzy' => true,            // master on/off for the Levenshtein fuzzy fallback
    'fuzzy_threshold' => 0.25,  // max edit distance as a fraction of the longer string
    'fuzzy_min_length' => 4,    // values/labels shorter than this skip fuzzy entirely
],
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

## Per-Panel Configuration (Plugin)

For apps with multiple Filament panels — typically an admin panel plus customer/partner panels — register `FilamentSolarisPlugin` on each panel to override the global defaults. Every setting in `config/filament-solaris.php` that's worth varying per audience is exposed as a fluent setter:

```php
use Statikbe\FilamentSolaris\FilamentSolarisPlugin;

// app/Providers/Filament/AdminPanelProvider.php
$panel->plugin(
    FilamentSolarisPlugin::make()
        ->defaultProvider('anthropic', 'claude-sonnet-4-5')
        ->defaultTemperature(0.3)
        ->defaultMaxTokens(4096)
        ->actionIcon('heroicon-o-sparkles')
        ->locales(['en', 'nl', 'fr'])
        ->promptLogging(true)
);

// app/Providers/Filament/CustomerPanelProvider.php
$panel->plugin(
    FilamentSolarisPlugin::make()
        ->defaultProvider('google', 'gemini-2.5-flash')   // cheaper for end-user features
        ->defaultMaxTokens(1024)                           // tighter token budget
        ->defaultImageDisk('public')
        ->visible(fn () => auth()->user()?->plan === 'pro') // hide AI for free-tier users
);
```

Available setters (every Tier-1/2 config key has one):

- **Provider/model/timeout:** `defaultProvider()`, `defaultModel()`, `defaultTimeout()`
- **Text-gen options:** `defaultTemperature()`, `defaultMaxTokens()`, `defaultMaxSteps()`, `defaultTopP()`
- **Transcription:** `defaultTranscriptionProvider()`, `defaultTranscriptionModel()`, `defaultTranscriptionTimeout()`
- **Image generation:** `defaultImageProvider()`, `defaultImageModel()`, `defaultImageTimeout()`, `defaultImageSize()`, `defaultImageQuality()`, `defaultImageDisk()`, `defaultImageDirectory()`, `defaultImageVisibility()`
- **Option matching:** `optionFuzzyMatching()`, `optionFuzzyThreshold()`, `optionFuzzyMinLength()`
- **Logging:** `promptLogging()`, `promptLoggingChannel()`
- **Locales:** `defaultLocale()`, `locales()`
- **Icons:** `actionIcon()`, `dictationIcon()`, `imageGenerationIcon()`, `conversationSendIcon()`, `conversationAttachmentIcon()`
- **Tone:** `defaultTone()`
- **Preset overrides:** `presetProvider()` (single, repeatable) and `presetProviders()` (bulk merge)
- **Visibility gate:** `visible(bool|Closure)` and `disabled()`

**Visibility gate (`->visible(...)` / `->disabled()`).** Set a single panel-wide predicate; Solaris registers `->hidden(...)` on every Solaris action with the negated check, so it hard-AND's with whatever the consuming action sets via its own `->visible(...)`. Users can't accidentally show an action on a disabled panel.

**preset_providers merge semantics.** `presetProvider()` overrides one entry from `config/filament-solaris.php` at a time; entries you don't override stay live. `presetProviders([...])` merges with any prior `presetProvider()` calls on the same plugin.

**Outside a panel context** (queued jobs, CLI commands, non-panel Livewire components) Solaris falls through to `config/filament-solaris.php` — the plugin only applies when `Filament::getCurrentPanel()` returns a panel that registered it.
