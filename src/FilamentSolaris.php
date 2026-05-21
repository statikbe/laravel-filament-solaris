<?php

namespace Statikbe\FilamentSolaris;

use Psr\Log\LoggerInterface;

/**
 * Singleton service holding Solaris's runtime registries.
 *
 * Bound as a singleton in {@see FilamentSolarisServiceProvider::register()} —
 * runtime state (factory overrides, supported locales, custom logger) lives
 * on the bound instance and is shared across the request. Call via the
 * facade ({@see Facades\FilamentSolaris}) or by
 * resolving the class directly with `app(FilamentSolaris::class)`.
 */
class FilamentSolaris
{
    /**
     * Runtime-registered factory overrides.
     *
     * @var array<class-string, class-string>
     */
    protected array $runtimeFactories = [];

    /**
     * Runtime-set supported locales.
     *
     * @var array<int|string, string>|null
     */
    protected ?array $locales = null;

    /**
     * Runtime-registered logger.
     */
    protected ?LoggerInterface $logger = null;

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
        $this->runtimeFactories[$componentClass] = $factoryClass;
    }

    /**
     * Get all runtime-registered factories.
     *
     * @return array<class-string, class-string>
     */
    public function getRuntimeFactories(): array
    {
        return $this->runtimeFactories;
    }

    /**
     * Clear all runtime-registered factories (for testing).
     */
    public function clearRuntimeFactories(): void
    {
        $this->runtimeFactories = [];
    }

    /**
     * Set the supported locales at runtime.
     *
     * Accepts a flat array (['en', 'nl']) or a key-value array (['en' => 'English', 'nl' => 'Dutch']).
     *
     * @param  array<int|string, string>  $locales
     */
    public function setLocales(array $locales): void
    {
        $this->locales = $locales;
    }

    /**
     * Get the runtime-set locales.
     *
     * @return array<int|string, string>|null
     */
    public function getLocales(): ?array
    {
        return $this->locales;
    }

    /**
     * Clear runtime-set locales (for testing).
     */
    public function clearLocales(): void
    {
        $this->locales = null;
    }

    /**
     * Register runtime-set logger.
     */
    public function registerLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Get runtime logger.
     */
    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Clear runtime-set logger (for testing).
     */
    public function clearLogger(): void
    {
        $this->logger = null;
    }
}
