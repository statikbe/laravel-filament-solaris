<?php

use Filament\Forms\Components\Textarea;
use Statikbe\FilamentSolaris\Factories\TextFactory;
use Statikbe\FilamentSolaris\Prompts\Presets\SummaryPreset;

it('includes max words in prompt', function () {
    $preset = SummaryPreset::make()->maxWords(100);
    $factory = TextFactory::make(Textarea::make('summary'));

    $prompt = $preset->build(
        instruction: '',
        sourceData: ['body' => 'Article content...'],
        factories: ['summary' => $factory],
    );

    expect($prompt)->toContain('100');
});

it('includes tone in prompt', function () {
    $preset = SummaryPreset::make()->tone('formal');
    $factory = TextFactory::make(Textarea::make('summary'));

    $prompt = $preset->build(
        instruction: '',
        sourceData: ['body' => 'Content'],
        factories: ['summary' => $factory],
    );

    expect($prompt)->toContain('formal');
});

it('includes language override in prompt', function () {
    $preset = SummaryPreset::make()->language('French');
    $factory = TextFactory::make(Textarea::make('summary'));

    $prompt = $preset->build(
        instruction: '',
        sourceData: ['body' => 'Content'],
        factories: ['summary' => $factory],
    );

    expect($prompt)->toContain('French');
});

it('includes source data in prompt', function () {
    $preset = SummaryPreset::make();
    $factory = TextFactory::make(Textarea::make('summary'));

    $prompt = $preset->build(
        instruction: '',
        sourceData: ['body' => 'The article discusses AI trends.'],
        factories: ['summary' => $factory],
    );

    expect($prompt)->toContain('The article discusses AI trends.');
});

it('does not include JSON schema in prompt text', function () {
    $preset = SummaryPreset::make();
    $factory = TextFactory::make(Textarea::make('summary'));

    $prompt = $preset->build(
        instruction: '',
        sourceData: ['body' => 'Content'],
        factories: ['summary' => $factory],
    );

    expect($prompt)->not->toContain('Response Format');
});
