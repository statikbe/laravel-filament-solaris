<?php

use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiAction;
use Statikbe\FilamentSolaris\Prompts\Presets\SummaryPreset;
use Statikbe\FilamentSolaris\Testing\AiActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\ProviderFormComponent;

beforeEach(function () {
    AiActionFake::reset();
    config()->set('filament-solaris.ai.default_temperature', null);
    config()->set('filament-solaris.ai.default_max_tokens', null);
    config()->set('filament-solaris.ai.default_max_steps', null);
    config()->set('filament-solaris.ai.default_top_p', null);
    config()->set('filament-solaris.preset_providers', []);
});

afterEach(function () {
    AiActionFake::reset();
});

it('records null options when none configured', function () {
    AiAction::fake(['summary' => 'Test']);

    Livewire::test(ProviderFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateSummary');

    AiAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options) {
        expect($options['temperature'])->toBeNull()
            ->and($options['max_tokens'])->toBeNull()
            ->and($options['max_steps'])->toBeNull()
            ->and($options['top_p'])->toBeNull();
    });
});

it('records action-level options', function () {
    AiAction::fake(['summary' => 'Test']);

    Livewire::test(ProviderFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateWithOptions');

    AiAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options) {
        expect($options['temperature'])->toBe(0.7)
            ->and($options['max_tokens'])->toBe(2048)
            ->and($options['max_steps'])->toBe(5)
            ->and($options['top_p'])->toBe(0.95);
    });
});

it('records preset-level options', function () {
    AiAction::fake(['summary' => 'Test']);

    Livewire::test(ProviderFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateWithPresetOptions');

    AiAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options) {
        expect($options['temperature'])->toBe(0.3)
            ->and($options['max_tokens'])->toBe(512)
            ->and($options['max_steps'])->toBeNull()
            ->and($options['top_p'])->toBeNull();
    });
});

it('action options override preset options', function () {
    AiAction::fake(['summary' => 'Test']);

    Livewire::test(ProviderFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateWithBothOptions');

    AiAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options) {
        expect($options['temperature'])->toBe(0.9)        // action wins
            ->and($options['max_tokens'])->toBe(512);     // preset value passes through (action didn't set)
    });
});

it('records preset_providers config options', function () {
    config()->set('filament-solaris.preset_providers', [
        SummaryPreset::class => [
            'temperature' => 0.5,
            'max_tokens' => 256,
        ],
    ]);

    AiAction::fake(['summary' => 'Test']);

    Livewire::test(ProviderFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateWithPreset');

    AiAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options) {
        expect($options['temperature'])->toBe(0.5)
            ->and($options['max_tokens'])->toBe(256);
    });
});

it('records config defaults when nothing else set', function () {
    config()->set('filament-solaris.ai.default_temperature', 0.42);
    config()->set('filament-solaris.ai.default_max_tokens', 1234);
    config()->set('filament-solaris.ai.default_max_steps', 7);
    config()->set('filament-solaris.ai.default_top_p', 0.85);

    AiAction::fake(['summary' => 'Test']);

    Livewire::test(ProviderFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateSummary');

    AiAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options) {
        expect($options['temperature'])->toBe(0.42)
            ->and($options['max_tokens'])->toBe(1234)
            ->and($options['max_steps'])->toBe(7)
            ->and($options['top_p'])->toBe(0.85);
    });
});

it('preset overrides config default', function () {
    config()->set('filament-solaris.ai.default_temperature', 0.1);

    AiAction::fake(['summary' => 'Test']);

    Livewire::test(ProviderFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateWithPresetOptions');

    AiAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options) {
        expect($options['temperature'])->toBe(0.3); // preset wins over config default
    });
});

it('preset_providers config overrides config default but loses to preset method', function () {
    config()->set('filament-solaris.ai.default_temperature', 0.1);
    config()->set('filament-solaris.preset_providers', [
        SummaryPreset::class => ['temperature' => 0.2],
    ]);

    AiAction::fake(['summary' => 'Test']);

    // generateWithPresetOptions sets temperature(0.3) directly on the preset
    Livewire::test(ProviderFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateWithPresetOptions');

    AiAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options) {
        expect($options['temperature'])->toBe(0.3); // preset method wins over preset_providers config
    });
});

it('preset_providers config wins over config default', function () {
    config()->set('filament-solaris.ai.default_temperature', 0.1);
    config()->set('filament-solaris.preset_providers', [
        SummaryPreset::class => ['temperature' => 0.2],
    ]);

    AiAction::fake(['summary' => 'Test']);

    Livewire::test(ProviderFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateWithPreset');

    AiAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options) {
        expect($options['temperature'])->toBe(0.2);
    });
});

it('resolves Closure temperature at call time', function () {
    AiAction::fake(['summary' => 'Test']);

    Livewire::test(ProviderFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateWithClosureTemperature');

    AiAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options) {
        expect($options['temperature'])->toBe(0.42);
    });
});
