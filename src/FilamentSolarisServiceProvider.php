<?php

namespace Statikbe\FilamentSolaris;

use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Facades\FilamentAsset;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Statikbe\FilamentSolaris\Factories\SpatieMediaLibraryFileUploadFactory;

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

    public function packageBooted(): void
    {
        FilamentAsset::register([
            AlpineComponent::make('dictation-modal', __DIR__.'/../dist/components/dictation.js'),
        ], 'statikbe/filament-solaris');

        // Auto-register the Spatie media-library variant when both Filament's
        // plugin and Spatie's media-library are installed. No-op otherwise —
        // the package stays usable without either dependency.
        if (class_exists(SpatieMediaLibraryFileUpload::class)
            && class_exists(Media::class)
        ) {
            FilamentSolaris::registerFactory(
                SpatieMediaLibraryFileUpload::class,
                SpatieMediaLibraryFileUploadFactory::class,
            );
        }
    }
}
