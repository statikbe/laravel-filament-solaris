<?php

namespace Statikbe\FilamentSolaris;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentSolarisServiceProvider extends PackageServiceProvider
{
    const PACKAGE_NAME = 'filament-solaris';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::PACKAGE_NAME)
            ->hasConfigFile()
            ->hasViews()
            ->hasTranslations();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(FilamentSolarisConfig::class);
    }
}
