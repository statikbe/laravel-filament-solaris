<?php

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Statikbe\FilamentSolaris\Support\UserInput;

it('creates default textarea with prompt label', function () {
    $userInput = UserInput::make();
    $schema = $userInput->toFormSchema();

    expect($schema)->toHaveCount(1)
        ->and($schema[0])->toBeInstanceOf(Textarea::class)
        ->and($schema[0]->getName())->toBe('user_instructions');
});

it('sets custom placeholder on default textarea', function () {
    $userInput = UserInput::make()
        ->placeholder('Type your instructions here...');

    $schema = $userInput->toFormSchema();

    expect($schema)->toHaveCount(1)
        ->and($schema[0])->toBeInstanceOf(Textarea::class);
});

it('sets custom prompt label', function () {
    $userInput = UserInput::make()
        ->prompt('What would you like?');

    $schema = $userInput->toFormSchema();

    expect($schema)->toHaveCount(1)
        ->and($schema[0])->toBeInstanceOf(Textarea::class);
});

it('returns custom fields when set', function () {
    $select = Select::make('language')->options(['en' => 'English', 'nl' => 'Dutch']);

    $userInput = UserInput::make()
        ->fields([$select]);

    $schema = $userInput->toFormSchema();

    expect($schema)->toHaveCount(1)
        ->and($schema[0])->toBeInstanceOf(Select::class)
        ->and($schema[0]->getName())->toBe('language');
});

it('custom fields override default textarea', function () {
    $userInput = UserInput::make()
        ->prompt('This should be ignored')
        ->placeholder('Also ignored')
        ->fields([
            Select::make('tone')->options(['formal' => 'Formal', 'casual' => 'Casual']),
        ]);

    $schema = $userInput->toFormSchema();

    expect($schema)->toHaveCount(1)
        ->and($schema[0])->toBeInstanceOf(Select::class);
});
