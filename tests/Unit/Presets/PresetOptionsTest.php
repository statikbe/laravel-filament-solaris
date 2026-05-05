<?php

use Statikbe\FilamentSolaris\Prompts\Presets\SummaryPreset;

it('returns null options by default', function () {
    $preset = SummaryPreset::make();

    expect($preset->getTemperature())->toBeNull()
        ->and($preset->getMaxTokens())->toBeNull()
        ->and($preset->getMaxSteps())->toBeNull()
        ->and($preset->getTopP())->toBeNull();
});

it('sets temperature on preset', function () {
    $preset = SummaryPreset::make()->temperature(0.5);

    expect($preset->getTemperature())->toBe(0.5);
});

it('sets maxTokens on preset', function () {
    $preset = SummaryPreset::make()->maxTokens(1024);

    expect($preset->getMaxTokens())->toBe(1024);
});

it('sets maxSteps on preset', function () {
    $preset = SummaryPreset::make()->maxSteps(3);

    expect($preset->getMaxSteps())->toBe(3);
});

it('sets topP on preset', function () {
    $preset = SummaryPreset::make()->topP(0.95);

    expect($preset->getTopP())->toBe(0.95);
});

it('chains preset option setters fluently', function () {
    $preset = SummaryPreset::make();

    expect($preset->temperature(0.5))->toBe($preset)
        ->and($preset->maxTokens(100))->toBe($preset)
        ->and($preset->maxSteps(2))->toBe($preset)
        ->and($preset->topP(0.8))->toBe($preset);
});
