<?php

use Filament\Forms\Components\Select;
use Statikbe\FilamentSolaris\Factories\SelectFactory;
use Statikbe\FilamentSolaris\Prompts\Presets\ClassificationPreset;

it('includes context in prompt', function () {
    $preset = ClassificationPreset::make()->context('technology blog');
    $factory = SelectFactory::make(Select::make('category')->options(['news' => 'News']));

    $prompt = $preset->build(
        instruction: '',
        sourceData: ['body' => 'Content'],
        factories: ['category' => $factory],
    );

    expect($prompt)->toContain('technology blog');
});

it('includes single selection instruction by default', function () {
    $preset = ClassificationPreset::make();
    $factory = SelectFactory::make(Select::make('category')->options(['news' => 'News']));

    $prompt = $preset->build(
        instruction: '',
        sourceData: ['body' => 'Content'],
        factories: ['category' => $factory],
    );

    expect($prompt)->toContain('exactly one');
});

it('includes multiple selection instruction when enabled', function () {
    $preset = ClassificationPreset::make()->allowMultiple();
    $factory = SelectFactory::make(Select::make('category')->options(['news' => 'News']));

    $prompt = $preset->build(
        instruction: '',
        sourceData: ['body' => 'Content'],
        factories: ['category' => $factory],
    );

    expect($prompt)->toContain('multiple');
});
