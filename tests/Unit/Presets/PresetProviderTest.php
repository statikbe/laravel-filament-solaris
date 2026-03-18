<?php

use Laravel\Ai\Enums\Lab;
use Statikbe\FilamentSolaris\Prompts\Presets\SummaryPreset;

it('has null provider by default', function () {
    $preset = SummaryPreset::make();

    expect($preset->getProvider())->toBeNull()
        ->and($preset->getModel())->toBeNull();
});

it('sets string provider', function () {
    $preset = SummaryPreset::make()->provider('openai');

    expect($preset->getProvider())->toBe('openai')
        ->and($preset->getModel())->toBeNull();
});

it('sets provider with model', function () {
    $preset = SummaryPreset::make()->provider('openai', 'gpt-4o');

    expect($preset->getProvider())->toBe('openai')
        ->and($preset->getModel())->toBe('gpt-4o');
});

it('sets Lab enum provider', function () {
    $preset = SummaryPreset::make()->provider(Lab::Anthropic);

    expect($preset->getProvider())->toBe(Lab::Anthropic);
});

it('sets failover array provider', function () {
    $failover = ['openai' => 'gpt-4o', 'anthropic' => 'claude-sonnet-4-5-20250514'];
    $preset = SummaryPreset::make()->provider($failover);

    expect($preset->getProvider())->toBe($failover);
});

it('returns static for fluent chaining', function () {
    $preset = SummaryPreset::make();
    $result = $preset->provider('openai', 'gpt-4o');

    expect($result)->toBe($preset);
});
