# Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag="filament-solaris-config"
```

This creates `config/filament-solaris.php` with all available options.

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

## Default Locale

Override the locale used in prompts. When `null`, `app()->getLocale()` is used. Individual actions can override this with `->locale()`.

```php
'default_locale' => null,
```

## Action Icon

The default icon for AI action buttons.

```php
'action_icon' => Heroicon::OutlinedSparkles,
```

## Default Tone

The default tone used by presets like `SummaryPreset` and `GenerationPreset`. Individual presets can override this with `->tone()`.

```php
'default_tone' => 'neutral',
```

## Supported Locales

The locales available for the `TranslationPreset` language selector.

**Fallback chain** (first non-null wins):
1. Runtime: `FilamentSolaris::setLocales([...])` in a service provider
2. This config key
3. `config('app.supported_locales')`
4. `[config('app.locale')]` (single-locale fallback)

Accepts a flat array or key-value array:

```php
// Flat — display names resolved automatically via intl extension
'supported_locales' => ['en', 'nl', 'fr'],

// Key-value — display names used as-is
'supported_locales' => ['en' => 'English', 'nl' => 'Dutch', 'fr' => 'French'],
```

## Prompt Logging

Log the composed prompt and JSON schema before each AI call. Useful for debugging during development.

```php
'prompt_logging_enabled' => (bool) env('FILAMENT_SOLARIS_PROMPT_LOGGING', false),
'prompt_logging_channel' => null, // null = default Laravel log channel
```

Custom loggers can also be registered via `FilamentSolaris::registerLogger()` in a service provider.

## AI Provider & Model

Override which AI provider and model are used for all actions. When `null`, the `laravel/ai` default (`config('ai.default')`) is used.

```php
'default_provider' => null,
'default_model' => null,
'default_timeout' => null, // timeout in seconds, null = laravel/ai default (60s)
```

Supports all provider types accepted by `laravel/ai`:

```php
// Single provider string
'default_provider' => 'openai',

// Provider + model
'default_provider' => 'anthropic',
'default_model' => 'claude-sonnet-4-5-20250514',

// Failover array — tries providers in order
'default_provider' => ['openai' => 'gpt-4o', 'anthropic' => 'claude-sonnet-4-5-20250514'],
```

### Resolution Chain

Provider, model, and timeout are each resolved with a priority chain (highest wins):

**Provider & model:**
1. Action-level `->provider()`
2. Preset-level `->provider()` on the preset object
3. Config `preset_providers[PresetClass]` provider/model
4. Config `default_provider` / `default_model`
5. laravel/ai default (`config('ai.default')`)

**Timeout:**
1. Action-level `->timeout()`
2. Config `preset_providers[PresetClass]` timeout
3. Config `default_timeout`
4. laravel/ai default (60s)

## Transcription Provider & Model

Default provider for the transcription step in `DictationAction`. Separate from the AI processing provider.

```php
'default_transcription_provider' => null,
'default_transcription_model' => null,
'default_transcription_timeout' => null, // timeout in seconds for transcription calls
```

Actions can override these with `->transcriptionProvider()` and `->transcriptionTimeout()`:

```php
DictationAction::make('voice')
    ->transcriptionProvider('openai', 'whisper-1')  // transcription provider
    ->transcriptionTimeout(30)                       // transcription timeout
    ->provider('anthropic')                          // AI processing provider
    ->timeout(120)                                   // AI processing timeout
```

## Per-Preset Provider Overrides

Route specific preset types to different providers, models, and timeouts. Useful for sending cheap tasks (classification) to cheaper/faster models while using capable models for complex tasks (generation).

```php
'preset_providers' => [
    \Statikbe\FilamentSolaris\Prompts\Presets\ClassificationPreset::class => [
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'timeout' => 30,  // fast model, short timeout
    ],
    \Statikbe\FilamentSolaris\Prompts\Presets\SummaryPreset::class => [
        'provider' => ['openai' => 'gpt-4o', 'anthropic'], // failover
        'timeout' => 120,
    ],
],
```

All three keys (`provider`, `model`, `timeout`) are optional. This is overridden by action-level `->provider()` / `->timeout()` calls.
