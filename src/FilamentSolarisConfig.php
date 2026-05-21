<?php

namespace Statikbe\FilamentSolaris;

use Filament\Support\Icons\Heroicon;
use Laravel\Ai\Enums\Lab;
use Locale;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris as FilamentSolarisFacade;

class FilamentSolarisConfig
{
    /**
     * Get a config value prefixed with the package name.
     */
    private function packageConfig(string $key, mixed $default = null): mixed
    {
        return config(FilamentSolarisServiceProvider::PACKAGE_NAME.'.'.$key, $default);
    }

    /**
     * Resolve a config value, consulting the current Filament panel's
     * {@see FilamentSolarisPlugin} (if registered + overriding this key)
     * before falling back to global `config/filament-solaris.php`.
     *
     * Using an explicit `hasOverride()` check rather than `??` so a plugin
     * that explicitly overrides a key to `null` (e.g. clearing a default)
     * is honoured, not silently bypassed.
     */
    private function resolveConfig(string $key, mixed $default = null): mixed
    {
        $plugin = FilamentSolarisPlugin::current();

        if ($plugin !== null && $plugin->hasOverride($key)) {
            return $plugin->getOverride($key);
        }

        return $this->packageConfig($key, $default);
    }

    /**
     * Get the factory map from config.
     *
     * @return array<class-string, class-string>
     */
    public function getFactoryMap(): array
    {
        return $this->packageConfig('factories', []);
    }

    /**
     * Get the max options threshold for Select/CheckboxList.
     */
    public function getMaxOptions(): int
    {
        return (int) $this->packageConfig('max_options', 100);
    }

    /**
     * Whether the Levenshtein fuzzy fallback is enabled for option matching.
     */
    public function isOptionFuzzyMatchingEnabled(): bool
    {
        return (bool) $this->resolveConfig('option_matching.fuzzy', true);
    }

    /**
     * Max edit distance for a fuzzy option match, as a fraction of the longer
     * string's length.
     */
    public function getOptionFuzzyThreshold(): float
    {
        return (float) $this->resolveConfig('option_matching.fuzzy_threshold', 0.25);
    }

    /**
     * Minimum value/label length below which fuzzy matching is skipped.
     */
    public function getOptionFuzzyMinLength(): int
    {
        return (int) $this->resolveConfig('option_matching.fuzzy_min_length', 4);
    }

    /**
     * Get the default locale override.
     */
    public function getDefaultLocale(): ?string
    {
        return $this->resolveConfig('locale.default');
    }

    /**
     * Get the action icon.
     */
    public function getActionIcon(): string|\BackedEnum
    {
        return $this->resolveConfig('icons.action', Heroicon::OutlinedSparkles);
    }

    /**
     * Get the dictation action icon.
     */
    public function getDictationIcon(): string|\BackedEnum
    {
        return $this->resolveConfig('icons.dictation', Heroicon::OutlinedMicrophone);
    }

    /**
     * Get the conversational refinement send icon.
     */
    public function getConversationSendIcon(): string|\BackedEnum
    {
        return $this->resolveConfig('icons.conversation_send', Heroicon::OutlinedPaperAirplane);
    }

    /**
     * Get the conversational refinement attach-files icon.
     */
    public function getConversationAttachmentIcon(): string|\BackedEnum
    {
        return $this->resolveConfig('icons.conversation_attachment', Heroicon::OutlinedPaperClip);
    }

    /**
     * Get the default tone.
     */
    public function getDefaultTone(): string
    {
        return (string) $this->resolveConfig('default_tone', 'neutral');
    }

    /**
     * Get the supported locales as a flat array of locale codes.
     *
     * Fallback chain: runtime (setLocales) → package config → app.supported_locales → [app.locale]
     *
     * Supports both flat arrays (['en', 'nl']) and key-value arrays (['en' => 'English']).
     * When a key-value array is provided, the keys are returned.
     *
     * @return array<int, string>
     */
    public function getSupportedLocales(): array
    {
        $locales = $this->resolveRawLocales();

        // Key-value array: keys are locale codes
        if (! array_is_list($locales)) {
            return array_keys($locales);
        }

        return $locales;
    }

    /**
     * Get a locale → display name map for all supported locales.
     *
     * Display names are resolved in order:
     * 1. Key-value array values (if configured as ['en' => 'English'])
     * 2. Translation file (languages key)
     * 3. intl extension (Locale::getDisplayLanguage)
     * 4. Raw locale code as fallback
     *
     * @return array<string, string>
     */
    public function getSupportedLocaleOptions(): array
    {
        $locales = $this->resolveRawLocales();
        $translations = filament_solaris_trans('languages');
        $translations = is_array($translations) ? $translations : [];

        // Key-value array: values are display names
        if (! array_is_list($locales)) {
            /** @var array<string, string> $locales */
            return $locales;
        }

        return collect($locales)
            ->mapWithKeys(fn (string $locale) => [
                $locale => $translations[$locale] ?? $this->resolveLocaleName($locale),
            ])
            ->all();
    }

    /**
     * Resolve a locale code to a human-readable display name.
     *
     * Resolution order: translation file → intl extension → raw locale code.
     */
    public function resolveLocaleName(string $locale, ?string $inLocale = null): string
    {
        $translations = filament_solaris_trans('languages');

        if (is_array($translations) && isset($translations[$locale])) {
            return $translations[$locale];
        }

        if (class_exists(Locale::class) && function_exists('locale_get_display_language')) {
            $name = Locale::getDisplayLanguage($locale, $inLocale ?? app()->getLocale());

            if ($name && $name !== $locale) {
                return ucfirst($name);
            }
        }

        return $locale;
    }

    /**
     * Get the raw locales array from the fallback chain.
     *
     * @return array<int|string, string>
     */
    private function resolveRawLocales(): array
    {
        return FilamentSolarisFacade::getLocales()
            ?? $this->resolveConfig('locale.supported')
            ?? config('app.supported_locales')
            ?? [config('app.locale', 'en')];
    }

    /**
     * Check if prompt logging is enabled.
     */
    public function isPromptLoggingEnabled(): bool
    {
        return (bool) $this->resolveConfig('prompt_logging.enabled', false);
    }

    public function getPromptLoggingChannel(): ?string
    {
        return $this->resolveConfig('prompt_logging.channel');
    }

    /**
     * Get the default AI provider.
     *
     * @return Lab|array<string, string>|array<int, string>|string|null
     */
    public function getDefaultProvider(): Lab|array|string|null
    {
        return $this->resolveConfig('ai.default_provider');
    }

    /**
     * Get the default AI model.
     */
    public function getDefaultModel(): ?string
    {
        return $this->resolveConfig('ai.default_model');
    }

    /**
     * Get the default timeout in seconds.
     */
    public function getDefaultTimeout(): ?int
    {
        $value = $this->resolveConfig('ai.default_timeout');

        return $value !== null ? (int) $value : null;
    }

    public function getDefaultTemperature(): ?float
    {
        $value = $this->resolveConfig('ai.default_temperature');

        return $value !== null ? (float) $value : null;
    }

    public function getDefaultMaxTokens(): ?int
    {
        $value = $this->resolveConfig('ai.default_max_tokens');

        return $value !== null ? (int) $value : null;
    }

    public function getDefaultMaxSteps(): ?int
    {
        $value = $this->resolveConfig('ai.default_max_steps');

        return $value !== null ? (int) $value : null;
    }

    public function getDefaultTopP(): ?float
    {
        $value = $this->resolveConfig('ai.default_top_p');

        return $value !== null ? (float) $value : null;
    }

    /**
     * Get the default transcription provider.
     *
     * @return Lab|array<string, string>|array<int, string>|string|null
     */
    public function getDefaultTranscriptionProvider(): Lab|array|string|null
    {
        return $this->resolveConfig('transcription.default_provider');
    }

    /**
     * Get the default transcription model.
     */
    public function getDefaultTranscriptionModel(): ?string
    {
        return $this->resolveConfig('transcription.default_model');
    }

    /**
     * Get the default transcription timeout in seconds.
     */
    public function getDefaultTranscriptionTimeout(): ?int
    {
        $value = $this->resolveConfig('transcription.default_timeout');

        return $value !== null ? (int) $value : null;
    }

    /**
     * Get the image generation icon.
     */
    public function getImageGenerationIcon(): string|\BackedEnum
    {
        return $this->resolveConfig('icons.image_generation', Heroicon::OutlinedPhoto);
    }

    /**
     * Get the default image generation provider.
     *
     * @return Lab|array<string, string>|array<int, string>|string|null
     */
    public function getDefaultImageProvider(): Lab|array|string|null
    {
        return $this->resolveConfig('image_generation.default_provider');
    }

    /**
     * Get the default image generation model.
     */
    public function getDefaultImageModel(): ?string
    {
        return $this->resolveConfig('image_generation.default_model');
    }

    /**
     * Get the default image generation timeout in seconds.
     */
    public function getDefaultImageTimeout(): ?int
    {
        $value = $this->resolveConfig('image_generation.default_timeout');

        return $value !== null ? (int) $value : null;
    }

    /**
     * Get the default image size.
     */
    public function getDefaultImageSize(): ?string
    {
        return $this->resolveConfig('image_generation.default_size');
    }

    /**
     * Get the default image quality.
     */
    public function getDefaultImageQuality(): ?string
    {
        return $this->resolveConfig('image_generation.default_quality');
    }

    /**
     * Get the default image storage disk.
     */
    public function getDefaultImageDisk(): ?string
    {
        return $this->resolveConfig('image_generation.default_disk');
    }

    /**
     * Get the default image storage directory.
     */
    public function getDefaultImageDirectory(): string
    {
        return (string) $this->resolveConfig('image_generation.default_directory', 'ai-images');
    }

    /**
     * Get the default image storage visibility.
     */
    public function getDefaultImageVisibility(): ?string
    {
        return $this->resolveConfig('image_generation.default_visibility');
    }

    /**
     * Get the per-preset overrides for a specific preset class.
     *
     * Merges the current panel's plugin preset entry (if any) on top of the
     * global config entry so an app can supplement, not just replace.
     *
     * @return array{provider: Lab|array|string|null, model: ?string, timeout: ?int, temperature: ?float, max_tokens: ?int, max_steps: ?int, top_p: ?float}
     */
    public function getPresetProvider(string $presetClass): array
    {
        $globalEntry = $this->packageConfig('preset_providers', [])[$presetClass] ?? [];
        $pluginEntry = $this->pluginPresetEntry($presetClass);

        $config = array_merge($globalEntry, $pluginEntry);

        return [
            'provider' => $config['provider'] ?? null,
            'model' => $config['model'] ?? null,
            'timeout' => isset($config['timeout']) ? (int) $config['timeout'] : null,
            'temperature' => isset($config['temperature']) ? (float) $config['temperature'] : null,
            'max_tokens' => isset($config['max_tokens']) ? (int) $config['max_tokens'] : null,
            'max_steps' => isset($config['max_steps']) ? (int) $config['max_steps'] : null,
            'top_p' => isset($config['top_p']) ? (float) $config['top_p'] : null,
        ];
    }

    /**
     * Look up the current panel plugin's preset entry for a given preset class.
     *
     * @return array<string, mixed>
     */
    private function pluginPresetEntry(string $presetClass): array
    {
        $plugin = FilamentSolarisPlugin::current();

        if ($plugin === null) {
            return [];
        }

        $presetProviders = $plugin->getOverride('preset_providers') ?? [];

        return $presetProviders[$presetClass] ?? [];
    }

    /**
     * Get the resolved factory map combining config and runtime registrations.
     *
     * @return array<class-string, class-string>
     */
    public function getResolvedFactoryMap(): array
    {
        return array_merge(
            $this->getFactoryMap(),
            FilamentSolarisFacade::getRuntimeFactories(),
        );
    }
}
