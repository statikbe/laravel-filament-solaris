<?php

use Statikbe\FilamentSolaris\FilamentSolaris;
use Statikbe\FilamentSolaris\FilamentSolarisConfig;

beforeEach(function () {
    FilamentSolaris::clearLocales();
});

afterEach(function () {
    FilamentSolaris::clearLocales();
});

it('falls back to app locale when no locales are configured', function () {
    config()->set('filament-solaris.supported_locales', null);
    config()->set('app.supported_locales', null);
    config()->set('app.locale', 'de');

    $config = app(FilamentSolarisConfig::class);

    expect($config->getSupportedLocales())->toBe(['de']);
});

it('reads locales from app.supported_locales as fallback', function () {
    config()->set('filament-solaris.supported_locales', null);
    config()->set('app.supported_locales', ['en', 'fr']);

    $config = app(FilamentSolarisConfig::class);

    expect($config->getSupportedLocales())->toBe(['en', 'fr']);
});

it('reads locales from package config', function () {
    config()->set('filament-solaris.supported_locales', ['nl', 'de']);
    config()->set('app.supported_locales', ['en', 'fr']);

    $config = app(FilamentSolarisConfig::class);

    expect($config->getSupportedLocales())->toBe(['nl', 'de']);
});

it('prefers runtime locales over config', function () {
    config()->set('filament-solaris.supported_locales', ['nl', 'de']);

    FilamentSolaris::setLocales(['ja', 'ko']);

    $config = app(FilamentSolarisConfig::class);

    expect($config->getSupportedLocales())->toBe(['ja', 'ko']);
});

it('extracts locale codes from key-value config array', function () {
    config()->set('filament-solaris.supported_locales', [
        'en' => 'English',
        'nl' => 'Dutch',
    ]);

    $config = app(FilamentSolarisConfig::class);

    expect($config->getSupportedLocales())->toBe(['en', 'nl']);
});

it('extracts locale codes from key-value runtime array', function () {
    FilamentSolaris::setLocales([
        'fr' => 'Français',
        'de' => 'Deutsch',
    ]);

    $config = app(FilamentSolarisConfig::class);

    expect($config->getSupportedLocales())->toBe(['fr', 'de']);
});

it('returns key-value pairs as locale options from key-value config', function () {
    config()->set('filament-solaris.supported_locales', [
        'en' => 'English',
        'nl' => 'Dutch',
    ]);

    $config = app(FilamentSolarisConfig::class);

    expect($config->getSupportedLocaleOptions())->toBe([
        'en' => 'English',
        'nl' => 'Dutch',
    ]);
});

it('resolves display names from translation file for flat array', function () {
    config()->set('filament-solaris.supported_locales', ['en', 'nl']);

    $config = app(FilamentSolarisConfig::class);
    $options = $config->getSupportedLocaleOptions();

    expect($options['en'])->toBe('English')
        ->and($options['nl'])->toBe('Dutch');
});

it('resolves display name via intl for untranslated locale', function () {
    config()->set('filament-solaris.supported_locales', ['uk']);

    $config = app(FilamentSolarisConfig::class);
    $name = $config->resolveLocaleName('uk', 'en');

    // intl should resolve 'uk' to 'Ukrainian'
    expect($name)->toBe('Ukrainian');
});

it('falls back to locale code when intl cannot resolve', function () {
    config()->set('filament-solaris.supported_locales', ['xx']);

    $config = app(FilamentSolarisConfig::class);
    $name = $config->resolveLocaleName('xx', 'en');

    expect($name)->toBe('xx');
});

it('clears runtime locales correctly', function () {
    FilamentSolaris::setLocales(['ja']);

    expect(FilamentSolaris::getLocales())->toBe(['ja']);

    FilamentSolaris::clearLocales();

    expect(FilamentSolaris::getLocales())->toBeNull();
});

it('resolves locale name from translation file first', function () {
    $config = app(FilamentSolarisConfig::class);

    // 'fr' exists in the translation file as 'French'
    expect($config->resolveLocaleName('fr', 'en'))->toBe('French');
});
