<?php

namespace Statikbe\FilamentSolaris;

use Psr\Log\LoggerInterface;

class FilamentSolaris
{
    /**
     * Runtime-registered factory overrides.
     *
     * @var array<class-string, class-string>
     */
    protected static array $runtimeFactories = [];

    /**
     * Runtime-set supported locales.
     *
     * @var array<int|string, string>|null
     */
    protected static ?array $locales = null;

    /**
     * Runtime-registered logger.
     *
     * @var ?LoggerInterface
     */
    protected static ?LoggerInterface $logger = null;

    /**
     * Get the config instance.
     */
    public function config(): FilamentSolarisConfig
    {
        return app(FilamentSolarisConfig::class);
    }

    /**
     * Register a custom factory for a Filament component class.
     *
     * @param  class-string  $componentClass
     * @param  class-string  $factoryClass
     */
    public function registerFactory(string $componentClass, string $factoryClass): void
    {
        static::$runtimeFactories[$componentClass] = $factoryClass;
    }

    /**
     * Get all runtime-registered factories.
     *
     * @return array<class-string, class-string>
     */
    public static function getRuntimeFactories(): array
    {
        return static::$runtimeFactories;
    }

    /**
     * Clear all runtime-registered factories (for testing).
     */
    public static function clearRuntimeFactories(): void
    {
        static::$runtimeFactories = [];
    }

    /**
     * Set the supported locales at runtime.
     *
     * Accepts a flat array (['en', 'nl']) or a key-value array (['en' => 'English', 'nl' => 'Dutch']).
     *
     * @param  array<int|string, string>  $locales
     */
    public static function setLocales(array $locales): void
    {
        static::$locales = $locales;
    }

    /**
     * Get the runtime-set locales.
     *
     * @return array<int|string, string>|null
     */
    public static function getLocales(): ?array
    {
        return static::$locales;
    }

    /**
     * Clear runtime-set locales (for testing).
     */
    public static function clearLocales(): void
    {
        static::$locales = null;
    }

    /**
     * Register runtime-set logger.
     */
    public static function registerLogger(LoggerInterface $logger): void
    {
        static::$logger = $logger;
    }

    /**
     * Get runtime logger.
     */
    public static function getLogger(): ?LoggerInterface
    {
        return static::$logger;
    }

    /**
     * Clear runtime-set logger (for testing).
     */
    public static function clearLogger(): void
    {
        static::$logger = null;
    }
}
