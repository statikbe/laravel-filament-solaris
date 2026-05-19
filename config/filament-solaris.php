<?php

// config for Statikbe/FilamentSolaris

use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Support\Icons\Heroicon;
use Statikbe\FilamentSolaris\Factories\BooleanFactory;
use Statikbe\FilamentSolaris\Factories\CheckboxListFactory;
use Statikbe\FilamentSolaris\Factories\FileUploadFactory;
use Statikbe\FilamentSolaris\Factories\MarkdownFactory;
use Statikbe\FilamentSolaris\Factories\RadioFactory;
use Statikbe\FilamentSolaris\Factories\RichEditorFactory;
use Statikbe\FilamentSolaris\Factories\SelectFactory;
use Statikbe\FilamentSolaris\Factories\TagsFactory;
use Statikbe\FilamentSolaris\Factories\TextFactory;

return [

    /*
    |--------------------------------------------------------------------------
    | Factory Map
    |--------------------------------------------------------------------------
    |
    | Maps Filament component classes to their corresponding AI factory classes.
    | You can override or extend this mapping to support custom components.
    |
    */

    'factories' => [
        Select::class => SelectFactory::class,
        Radio::class => RadioFactory::class,
        CheckboxList::class => CheckboxListFactory::class,
        Toggle::class => BooleanFactory::class,
        Checkbox::class => BooleanFactory::class,
        TextInput::class => TextFactory::class,
        Textarea::class => TextFactory::class,
        RichEditor::class => RichEditorFactory::class,
        MarkdownEditor::class => MarkdownFactory::class,
        ToggleButtons::class => SelectFactory::class,
        TagsInput::class => TagsFactory::class,
        CodeEditor::class => TextFactory::class,
        BaseFileUpload::class => FileUploadFactory::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Max Options
    |--------------------------------------------------------------------------
    |
    | Threshold above which Select/CheckboxList factories switch from strict
    | enum schema to free-text schema with fuzzy matching.
    |
    */

    'max_options' => 100,

    /*
    |--------------------------------------------------------------------------
    | Icons
    |--------------------------------------------------------------------------
    |
    | Default icons for the Solaris action buttons. Each entry accepts a
    | Heroicon name string or a BackedEnum that resolves to an icon name.
    |
    */

    'icons' => [
        'action' => Heroicon::OutlinedSparkles,
        'dictation' => Heroicon::OutlinedMicrophone,
        'image_generation' => Heroicon::OutlinedPhoto,
        'conversation_send' => Heroicon::OutlinedPaperAirplane,
        'conversation_attachment' => Heroicon::OutlinedPaperClip,
    ],

    /*
    |--------------------------------------------------------------------------
    | Locale
    |--------------------------------------------------------------------------
    |
    | `default` — override the locale used for AI prompts. When null, the
    | application locale (app()->getLocale()) is used.
    |
    | `supported` — locales available for the TranslatePreset language
    | selector. Fallback chain (first non-null wins):
    |   1. Runtime: FilamentSolaris::setLocales([...]) from a service provider.
    |   2. This config key.
    |   3. config('app.supported_locales').
    |   4. [config('app.locale')] (single-locale fallback).
    |
    | Accepts a flat array or a key-value array:
    |   ['en', 'nl', 'fr']                     — display names are resolved
    |                                             from the translation file
    |                                             or the intl extension.
    |   ['en' => 'English', 'nl' => 'Dutch']   — display names used as-is.
    |
    */

    'locale' => [
        'default' => null,
        'supported' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Tone
    |--------------------------------------------------------------------------
    |
    | The default tone used by presets such as Summarize and Generate.
    | Individual presets can override this via their tone() method.
    |
    */

    'default_tone' => 'neutral',

    /*
    |--------------------------------------------------------------------------
    | Prompt Logging
    |--------------------------------------------------------------------------
    |
    | When `enabled`, the composed prompt, JSON schema, and per-call Usage are
    | logged via Laravel's logger before/after each AI call. Useful for
    | debugging prompts during development. Should be disabled in production.
    |
    | `channel` lets you collect the prompt logs in a different log file.
    | Loggers can also be registered in service providers via
    | Statikbe\FilamentSolaris\Facades\FilamentSolaris::registerLogger().
    |
    */

    'prompt_logging' => [
        'enabled' => (bool) env('FILAMENT_SOLARIS_PROMPT_LOGGING', false),
        'channel' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Defaults
    |--------------------------------------------------------------------------
    |
    | Defaults for text-generation AI calls. When a value is null, the
    | laravel/ai default (or the agent's PHP attribute / provider default
    | for the text-generation options) is used. Supports failover arrays for
    | provider:
    |   'openai'
    |   ['openai' => 'gpt-4o', 'anthropic' => 'claude-sonnet-4-5-20250514']
    |
    | Text-generation options (temperature, max_tokens, max_steps, top_p) can
    | be overridden per-action (->temperature(), ->maxTokens(), ->maxSteps(),
    | ->topP()) or per-preset (Preset::make()->temperature()).
    |
    */

    'ai' => [
        'default_provider' => null,
        'default_model' => null,
        'default_timeout' => null,
        'default_temperature' => null,
        'default_max_tokens' => null,
        'default_max_steps' => null,
        'default_top_p' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Transcription Defaults
    |--------------------------------------------------------------------------
    |
    | Defaults for DictationFieldAction's transcription step. When null, the
    | laravel/ai default is used.
    |
    */

    'transcription' => [
        'default_provider' => null,
        'default_model' => null,
        'default_timeout' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Generation Defaults
    |--------------------------------------------------------------------------
    |
    | Defaults for ImageGenerationAction. When a value is null, the laravel/ai
    | default is used. The disk and directory should match the FileUpload
    | component's configuration for the generated image to display correctly.
    |
    */

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

    /*
    |--------------------------------------------------------------------------
    | Per-Preset Provider Overrides
    |--------------------------------------------------------------------------
    |
    | Override provider/model/timeout and text-generation options for specific
    | preset classes. Key is the preset FQCN. Useful for routing cheap tasks
    | to cheaper models or tuning sampling per preset.
    |
    | Example:
    |   \Statikbe\FilamentSolaris\Prompts\Presets\ClassificationPreset::class => [
    |       'provider' => 'openai',
    |       'model' => 'gpt-4o-mini',
    |       'timeout' => 30,
    |       'temperature' => 0.0,
    |       'max_tokens' => 256,
    |       'max_steps' => 1,
    |       'top_p' => 1.0,
    |   ],
    |
    */

    'preset_providers' => [],

];
