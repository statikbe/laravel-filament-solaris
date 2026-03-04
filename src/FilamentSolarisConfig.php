<?php

namespace Statikbe\FilamentSolaris;

use Filament\Support\Icons\Heroicon;
use Locale;

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
     * Get the default locale override.
     */
    public function getDefaultLocale(): ?string
    {
        return $this->packageConfig('default_locale');
    }

    /**
     * Get the action icon.
     */
    public function getActionIcon(): string|\BackedEnum
    {
        return $this->packageConfig('action_icon', Heroicon::OutlinedSparkles);
    }

    /**
     * Get the default tone.
     */
    public function getDefaultTone(): string
    {
        return (string) $this->packageConfig('default_tone', 'neutral');
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
        return FilamentSolaris::getLocales()
            ?? $this->packageConfig('supported_locales')
            ?? config('app.supported_locales')
            ?? [config('app.locale', 'en')];
    }

    /**
     * Check if prompt logging is enabled.
     */
    public function isPromptLoggingEnabled(): bool
    {
        return (bool) $this->packageConfig('prompt_logging_enabled', false);
    }

    public function getPromptLoggingChannel(): ?string
    {
        return $this->packageConfig('prompt_logging_channel');
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
            FilamentSolaris::getRuntimeFactories(),
        );
    }
}
