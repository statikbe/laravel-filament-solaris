<?php

use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Prompts\Presets\SummaryPreset;
use Statikbe\FilamentSolaris\Testing\AiFormActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\ProviderFormComponent;

beforeEach(function () {
    AiFormActionFake::reset();
    config()->set('filament-solaris.ai.default_provider', null);
    config()->set('filament-solaris.ai.default_model', null);
    config()->set('filament-solaris.preset_providers', []);
});

afterEach(function () {
    AiFormActionFake::reset();
});

it('records null provider when none configured', function () {
    AiFormAction::fake(['summary' => 'Test']);

    Livewire::test(ProviderFormComponent::class)
        ->fillForm(['title' => 'Test', 'body' => 'Content'])
        ->callAction('generateSummary');

    AiFormAction::assertCalledWith(function (array $sourceData, string $prompt, $provider, $model) {
        expect($provider)->toBeNull()
            ->and($model)->toBeNull();
    });
});

it('records action-level provider', function () {
    AiFormAction::fake(['summary' => 'Test']);

    Livewire::test(ProviderFormComponent::class)
        ->fillForm(['title' => 'Test', 'body' => 'Content'])
        ->callAction('generateWithProvider');

    AiFormAction::assertCalledWith(function (array $sourceData, string $prompt, $provider, $model) {
        expect($provider)->toBe('anthropic')
            ->and($model)->toBe('claude-sonnet-4-5-20250514');
    });
});

it('records preset-level provider', function () {
    AiFormAction::fake(['summary' => 'Test']);

    Livewire::test(ProviderFormComponent::class)
        ->fillForm(['title' => 'Test', 'body' => 'Content'])
        ->callAction('generateWithPresetProvider');

    AiFormAction::assertCalledWith(function (array $sourceData, string $prompt, $provider, $model) {
        expect($provider)->toBe('openai')
            ->and($model)->toBe('gpt-4o');
    });
});

it('records config default provider', function () {
    config()->set('filament-solaris.ai.default_provider', 'openai');
    config()->set('filament-solaris.ai.default_model', 'gpt-4o-mini');

    AiFormAction::fake(['summary' => 'Test']);

    Livewire::test(ProviderFormComponent::class)
        ->fillForm(['title' => 'Test', 'body' => 'Content'])
        ->callAction('generateSummary');

    AiFormAction::assertCalledWith(function (array $sourceData, string $prompt, $provider, $model) {
        expect($provider)->toBe('openai')
            ->and($model)->toBe('gpt-4o-mini');
    });
});

it('action provider overrides preset provider', function () {
    AiFormAction::fake(['summary' => 'Test']);

    Livewire::test(ProviderFormComponent::class)
        ->fillForm(['title' => 'Test', 'body' => 'Content'])
        ->callAction('generateWithBothProviders');

    AiFormAction::assertCalledWith(function (array $sourceData, string $prompt, $provider, $model) {
        // Action-level should win over preset-level
        expect($provider)->toBe('anthropic')
            ->and($model)->toBe('claude-sonnet-4-5-20250514');
    });
});

it('config preset_providers overrides config default', function () {
    config()->set('filament-solaris.ai.default_provider', 'anthropic');
    config()->set('filament-solaris.ai.default_model', 'claude-sonnet-4-5-20250514');
    config()->set('filament-solaris.preset_providers', [
        SummaryPreset::class => [
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
        ],
    ]);

    AiFormAction::fake(['summary' => 'Test']);

    Livewire::test(ProviderFormComponent::class)
        ->fillForm(['title' => 'Test', 'body' => 'Content'])
        ->callAction('generateWithPreset');

    AiFormAction::assertCalledWith(function (array $sourceData, string $prompt, $provider, $model) {
        expect($provider)->toBe('openai')
            ->and($model)->toBe('gpt-4o-mini');
    });
});

it('records failover array provider', function () {
    AiFormAction::fake(['summary' => 'Test']);

    Livewire::test(ProviderFormComponent::class)
        ->fillForm(['title' => 'Test', 'body' => 'Content'])
        ->callAction('generateWithFailover');

    AiFormAction::assertCalledWith(function (array $sourceData, string $prompt, $provider, $model) {
        expect($provider)->toBe(['openai' => 'gpt-4o', 'anthropic'])
            ->and($model)->toBeNull();
    });
});

it('records action-level timeout', function () {
    AiFormAction::fake(['summary' => 'Test']);

    Livewire::test(ProviderFormComponent::class)
        ->fillForm(['title' => 'Test', 'body' => 'Content'])
        ->callAction('generateWithTimeout');

    AiFormAction::assertCalledWith(function (array $sourceData, string $prompt, $provider, $model, $timeout) {
        expect($timeout)->toBe(120);
    });
});

it('records config default timeout', function () {
    config()->set('filament-solaris.ai.default_timeout', 90);

    AiFormAction::fake(['summary' => 'Test']);

    Livewire::test(ProviderFormComponent::class)
        ->fillForm(['title' => 'Test', 'body' => 'Content'])
        ->callAction('generateSummary');

    AiFormAction::assertCalledWith(function (array $sourceData, string $prompt, $provider, $model, $timeout) {
        expect($timeout)->toBe(90);
    });
});

it('records preset config timeout', function () {
    config()->set('filament-solaris.preset_providers', [
        SummaryPreset::class => [
            'timeout' => 45,
        ],
    ]);

    AiFormAction::fake(['summary' => 'Test']);

    Livewire::test(ProviderFormComponent::class)
        ->fillForm(['title' => 'Test', 'body' => 'Content'])
        ->callAction('generateWithPreset');

    AiFormAction::assertCalledWith(function (array $sourceData, string $prompt, $provider, $model, $timeout) {
        expect($timeout)->toBe(45);
    });
});

it('action timeout overrides preset config timeout', function () {
    config()->set('filament-solaris.preset_providers', [
        SummaryPreset::class => [
            'timeout' => 45,
        ],
    ]);

    AiFormAction::fake(['summary' => 'Test']);

    Livewire::test(ProviderFormComponent::class)
        ->fillForm(['title' => 'Test', 'body' => 'Content'])
        ->callAction('generateWithTimeout');

    AiFormAction::assertCalledWith(function (array $sourceData, string $prompt, $provider, $model, $timeout) {
        expect($timeout)->toBe(120); // action-level wins
    });
});

it('records null timeout when none configured', function () {
    AiFormAction::fake(['summary' => 'Test']);

    Livewire::test(ProviderFormComponent::class)
        ->fillForm(['title' => 'Test', 'body' => 'Content'])
        ->callAction('generateSummary');

    AiFormAction::assertCalledWith(function (array $sourceData, string $prompt, $provider, $model, $timeout) {
        expect($timeout)->toBeNull();
    });
});
