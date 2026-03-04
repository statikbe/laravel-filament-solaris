<?php

namespace Statikbe\FilamentSolaris\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Statikbe\FilamentSolaris\FilamentSolarisConfig config()
 * @method static void registerFactory(string $componentClass, string $factoryClass)
 * @method static void setLocales(array $locales)
 * @method static array|null getLocales()
 * @method static void clearLocales()
 * @method static array getRuntimeFactories()
 * @method static void clearRuntimeFactories()
 * @method static void registerLogger(\Psr\Log\LoggerInterface $logger)
 * @method static \Psr\Log\LoggerInterface|null getLogger()
 * @method static void clearLogger()
 *
 * @see \Statikbe\FilamentSolaris\FilamentSolaris
 */
class FilamentSolaris extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Statikbe\FilamentSolaris\FilamentSolaris::class;
    }
}
