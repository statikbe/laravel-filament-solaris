<?php

use Filament\Forms\Components\Textarea;
use Statikbe\FilamentSolaris\Factories\TextFactory;
use Statikbe\FilamentSolaris\Prompts\Presets\GenerationPreset;

it('includes tone in prompt', function () {
    $preset = GenerationPreset::make()->tone('casual');
    $factory = TextFactory::make(Textarea::make('body'));

    $prompt = $preset->build(
        instruction: '',
        sourceData: ['title' => 'Test'],
        factories: ['body' => $factory],
    );

    expect($prompt)->toContain('casual');
});

it('includes audience in prompt', function () {
    $preset = GenerationPreset::make()->audience('developers');
    $factory = TextFactory::make(Textarea::make('body'));

    $prompt = $preset->build(
        instruction: '',
        sourceData: ['title' => 'Test'],
        factories: ['body' => $factory],
    );

    expect($prompt)->toContain('developers');
});

it('includes style in prompt', function () {
    $preset = GenerationPreset::make()->style('blog post');
    $factory = TextFactory::make(Textarea::make('body'));

    $prompt = $preset->build(
        instruction: '',
        sourceData: ['title' => 'Test'],
        factories: ['body' => $factory],
    );

    expect($prompt)->toContain('blog post');
});

it('includes max length in prompt', function () {
    $preset = GenerationPreset::make()->maxLength(500);
    $factory = TextFactory::make(Textarea::make('body'));

    $prompt = $preset->build(
        instruction: '',
        sourceData: ['title' => 'Test'],
        factories: ['body' => $factory],
    );

    expect($prompt)->toContain('500');
});

it('provides default user input with textarea', function () {
    $preset = GenerationPreset::make();
    $userInput = $preset->defaultUserInput();

    expect($userInput)->not->toBeNull();

    $schema = $userInput->toFormSchema();
    expect($schema)->toHaveCount(1)
        ->and($schema[0])->toBeInstanceOf(Textarea::class);
});

it('works with combined configuration', function () {
    $preset = GenerationPreset::make()
        ->tone('formal')
        ->style('technical')
        ->audience('engineers')
        ->maxLength(1000);

    $factory = TextFactory::make(Textarea::make('body'));

    $prompt = $preset->build(
        instruction: '',
        sourceData: ['title' => 'AI in Manufacturing'],
        factories: ['body' => $factory],
    );

    expect($prompt)
        ->toContain('formal')
        ->toContain('technical')
        ->toContain('engineers')
        ->toContain('1000');
});
