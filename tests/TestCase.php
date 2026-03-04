<?php

namespace Statikbe\FilamentSolaris\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Statikbe\FilamentSolaris\FilamentSolarisServiceProvider;
use Statikbe\FilamentSolaris\Tests\Providers\TestPanelProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        // Clear compiled views to prevent stale cached views
        $viewsPath = __DIR__.'/../vendor/orchestra/testbench-core/laravel/storage/framework/views';
        if (is_dir($viewsPath)) {
            array_map('unlink', glob("{$viewsPath}/*.php"));
        }

        parent::setUp();

        // Filament's bind(DataStore::class, DataStoreOverride::class) breaks
        // Livewire's singleton registration. Re-register as a singleton so
        // store() always returns the same instance with the same WeakMap.
        $this->app->instance(
            \Livewire\Mechanisms\DataStore::class,
            $this->app->make(\Livewire\Mechanisms\DataStore::class),
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            ActionsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FilamentSolarisServiceProvider::class,
            FormsServiceProvider::class,
            LivewireServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            WidgetsServiceProvider::class,
            TestPanelProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }
}
