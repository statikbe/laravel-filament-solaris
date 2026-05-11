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
    | Default Locale
    |--------------------------------------------------------------------------
    |
    | Override the default locale for AI prompts. When null, the application
    | locale (app()->getLocale()) is used.
    |
    */

    'default_locale' => null,

    /*
    |--------------------------------------------------------------------------
    | Action Icon
    |--------------------------------------------------------------------------
    |
    | The default icon for AI actions. Accepts a Heroicon name string or a
    | BackedEnum that resolves to an icon name.
    |
    */

    'action_icon' => Heroicon::OutlinedSparkles,

    /*
    |--------------------------------------------------------------------------
    | Dictation Icon
    |--------------------------------------------------------------------------
    |
    | The default icon for dictation actions. Accepts a Heroicon name string
    | or a BackedEnum that resolves to an icon name.
    |
    */

    'dictation_icon' => Heroicon::OutlinedMicrophone,

    /*
    |--------------------------------------------------------------------------
    | Conversational Refinement Icon
    |--------------------------------------------------------------------------
    |
    | The icon for the send button in the conversational refinement chat.
    |
    */

    'conversation_send_icon' => Heroicon::OutlinedPaperAirplane,

    /*
    |--------------------------------------------------------------------------
    | Conversational Refinement Attachment Icon
    |--------------------------------------------------------------------------
    |
    | The icon for the attach-files button in the conversational refinement
    | chat (used to attach files to a refinement message).
    |
    */

    'conversation_attachment_icon' => Heroicon::OutlinedPaperClip,

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
    | Supported Locales
    |--------------------------------------------------------------------------
    |
    | The locales available for the TranslatePreset language selector.
    |
    | Fallback chain (first non-null wins):
    |   1. Runtime: FilamentSolaris::setLocales([...]) — call from a service
    |      provider to pass locales from another package.
    |   2. This config key.
    |   3. config('app.supported_locales').
    |   4. [config('app.locale')] (si
    ngle-locale fallback).
    |
    | Accepts a flat array or a key-value array:
    |   ['en', 'nl', 'fr']                     — display names are resolved
    |                                             from the translation file
    |                                             or the intl extension.
    |   ['en' => 'English', 'nl' => 'Dutch']   — display names used as-is.
    |
    */

    'supported_locales' => null,

    /*
    |--------------------------------------------------------------------------
    | Prompt Logging
    |--------------------------------------------------------------------------
    |
    | When enabled, the composed prompt and JSON schema are logged via
    | Laravel's logger before each AI call. Useful for debugging prompts
    | during development. Should be disabled in production.
    |
    | You can add a logging channel if you want to collect the prompt logs in
    | a different log file.
    |
    | Loggers can also be registered in service providers via
    | Statikbe\FilamentSolaris\Facades\FilamentSolaris::registerLogger()
    */

    'prompt_logging_enabled' => (bool) env('FILAMENT_SOLARIS_PROMPT_LOGGING', false),
    'prompt_logging_channel' => null,

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider & Model
    |--------------------------------------------------------------------------
    |
    | Default provider for all AI actions. When null, the laravel/ai default
    | (config('ai.default')) is used. Supports failover arrays.
    |
    | Examples:
    |   'openai'
    |   ['openai' => 'gpt-4o', 'anthropic' => 'claude-sonnet-4-5-20250514']
    |
    */

    'default_provider' => null,
    'default_model' => null,
    'default_timeout' => null,

    /*
    |--------------------------------------------------------------------------
    | Default Text Generation Options
    |--------------------------------------------------------------------------
    |
    | Defaults for text-generation parameters resolved by laravel/ai's
    | TextGenerationOptions. When null, laravel/ai falls back to the agent's
    | PHP attributes (#[Temperature], #[MaxTokens], #[MaxSteps], #[TopP]) and
    | then to the provider's own defaults.
    |
    | These can be overridden per-action (->temperature(), ->maxTokens(),
    | ->maxSteps(), ->topP()) or per-preset (Preset::make()->temperature()).
    |
    */

    'default_temperature' => null,
    'default_max_tokens' => null,
    'default_max_steps' => null,
    'default_top_p' => null,

    /*
    |--------------------------------------------------------------------------
    | Default Transcription Provider & Model
    |--------------------------------------------------------------------------
    |
    | Default provider for transcription (DictationAction). When null, the
    | laravel/ai default is used.
    |
    */

    'default_transcription_provider' => null,
    'default_transcription_model' => null,
    'default_transcription_timeout' => null,

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

    /*
    |--------------------------------------------------------------------------
    | Image Generation Icon
    |--------------------------------------------------------------------------
    |
    | The default icon for image generation actions.
    |
    */

    'image_generation_icon' => Heroicon::OutlinedPhoto,

    /*
    |--------------------------------------------------------------------------
    | Image Generation Defaults
    |--------------------------------------------------------------------------
    |
    | Default settings for AI image generation. When null, laravel/ai defaults
    | are used. The disk and directory should match the FileUpload component's
    | configuration for the generated image to display correctly.
    |
    */

    'default_image_provider' => null,
    'default_image_model' => null,
    'default_image_timeout' => null,
    'default_image_size' => null,           // '1:1', '3:2', '2:3'
    'default_image_quality' => null,        // 'low', 'medium', 'high'
    'default_image_disk' => null,           // Storage disk (null = default filesystem disk)
    'default_image_directory' => 'ai-images',
    'default_image_visibility' => null,     // 'public' or null

];
