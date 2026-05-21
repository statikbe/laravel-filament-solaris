<?php

use Filament\Forms\Components\Textarea;
use Statikbe\FilamentSolaris\Factories\TextFactory;
use Statikbe\FilamentSolaris\Prompts\Presets\TranslationPreset;

it('includes target language in prompt', function () {
    $preset = TranslationPreset::make()->language('French');
    $factory = TextFactory::make(Textarea::make('body'));

    $prompt = $preset->build(
        instruction: '',
        sourceData: ['body' => 'Hello world'],
        factories: ['body' => $factory],
    );

    expect($prompt)->toContain('French');
});

it('resolves a locale code to a language name in the prompt', function () {
    $preset = TranslationPreset::make()->language('fr');
    $factory = TextFactory::make(Textarea::make('body'));

    $prompt = $preset->build(
        instruction: '',
        sourceData: ['body' => 'Hello world'],
        factories: ['body' => $factory],
    );

    expect($prompt)->toContain('into French.');
});

it('throws when language is not set', function () {
    $preset = TranslationPreset::make();
    $factory = TextFactory::make(Textarea::make('body'));

    $preset->build(
        instruction: '',
        sourceData: ['body' => 'Hello'],
        factories: ['body' => $factory],
    );
})->throws(RuntimeException::class, 'TranslationPreset requires a target language');

it('includes formatting preservation instruction', function () {
    $preset = TranslationPreset::make()->language('French')->preserveFormatting();
    $factory = TextFactory::make(Textarea::make('body'));

    $prompt = $preset->build(
        instruction: '',
        sourceData: ['body' => '<p>Hello</p>'],
        factories: ['body' => $factory],
    );

    expect($prompt)->toContain('Preserve');
});

it('includes glossary in prompt', function () {
    $preset = TranslationPreset::make()
        ->language('French')
        ->glossary('API = API, user = utilisateur');

    $factory = TextFactory::make(Textarea::make('body'));

    $prompt = $preset->build(
        instruction: '',
        sourceData: ['body' => 'Hello'],
        factories: ['body' => $factory],
    );

    expect($prompt)->toContain('API = API, user = utilisateur');
});

it('provides default user input with language select', function () {
    $preset = TranslationPreset::make()->language('French');
    $userInput = $preset->defaultUserInput();

    expect($userInput)->not->toBeNull();

    $schema = $userInput->toFormSchema();
    expect($schema)->toHaveCount(1)
        ->and($schema[0]->getName())->toBe('language');
});
