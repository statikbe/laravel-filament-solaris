<?php

use Statikbe\FilamentSolaris\FilamentSolarisConfig;
use Statikbe\FilamentSolaris\Prompts\Presets\SummaryPreset;

it('returns null for default provider when not configured', function () {
    config()->set('filament-solaris.default_provider', null);

    $config = app(FilamentSolarisConfig::class);

    expect($config->getDefaultProvider())->toBeNull();
});

it('returns string provider from config', function () {
    config()->set('filament-solaris.default_provider', 'openai');

    $config = app(FilamentSolarisConfig::class);

    expect($config->getDefaultProvider())->toBe('openai');
});

it('returns failover array provider from config', function () {
    $failover = ['openai' => 'gpt-4o', 'anthropic' => 'claude-sonnet-4-5-20250514'];
    config()->set('filament-solaris.default_provider', $failover);

    $config = app(FilamentSolarisConfig::class);

    expect($config->getDefaultProvider())->toBe($failover);
});

it('returns null for default model when not configured', function () {
    config()->set('filament-solaris.default_model', null);

    $config = app(FilamentSolarisConfig::class);

    expect($config->getDefaultModel())->toBeNull();
});

it('returns default model from config', function () {
    config()->set('filament-solaris.default_model', 'gpt-4o');

    $config = app(FilamentSolarisConfig::class);

    expect($config->getDefaultModel())->toBe('gpt-4o');
});

it('returns transcription provider from config', function () {
    config()->set('filament-solaris.default_transcription_provider', 'openai');

    $config = app(FilamentSolarisConfig::class);

    expect($config->getDefaultTranscriptionProvider())->toBe('openai');
});

it('returns transcription model from config', function () {
    config()->set('filament-solaris.default_transcription_model', 'whisper-1');

    $config = app(FilamentSolarisConfig::class);

    expect($config->getDefaultTranscriptionModel())->toBe('whisper-1');
});

it('returns preset provider from config', function () {
    config()->set('filament-solaris.preset_providers', [
        SummaryPreset::class => [
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
        ],
    ]);

    $config = app(FilamentSolarisConfig::class);
    $result = $config->getPresetProvider(SummaryPreset::class);

    expect($result['provider'])->toBe('openai')
        ->and($result['model'])->toBe('gpt-4o-mini');
});

it('returns null provider/model for unconfigured preset', function () {
    config()->set('filament-solaris.preset_providers', []);

    $config = app(FilamentSolarisConfig::class);
    $result = $config->getPresetProvider(SummaryPreset::class);

    expect($result['provider'])->toBeNull()
        ->and($result['model'])->toBeNull()
        ->and($result['timeout'])->toBeNull();
});

it('returns preset timeout from config', function () {
    config()->set('filament-solaris.preset_providers', [
        SummaryPreset::class => [
            'provider' => 'openai',
            'timeout' => 30,
        ],
    ]);

    $config = app(FilamentSolarisConfig::class);
    $result = $config->getPresetProvider(SummaryPreset::class);

    expect($result['timeout'])->toBe(30);
});

it('returns default timeout from config', function () {
    config()->set('filament-solaris.default_timeout', 90);

    $config = app(FilamentSolarisConfig::class);

    expect($config->getDefaultTimeout())->toBe(90);
});

it('returns null default timeout when not configured', function () {
    config()->set('filament-solaris.default_timeout', null);

    $config = app(FilamentSolarisConfig::class);

    expect($config->getDefaultTimeout())->toBeNull();
});

it('supports failover array in preset provider config', function () {
    $failover = ['openai' => 'gpt-4o', 'anthropic'];
    config()->set('filament-solaris.preset_providers', [
        SummaryPreset::class => [
            'provider' => $failover,
        ],
    ]);

    $config = app(FilamentSolarisConfig::class);
    $result = $config->getPresetProvider(SummaryPreset::class);

    expect($result['provider'])->toBe($failover)
        ->and($result['model'])->toBeNull();
});
