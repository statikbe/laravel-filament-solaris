<?php

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Factories\TextFactory;
use Statikbe\FilamentSolaris\Prompts\InlinePromptBuilder;
use Statikbe\FilamentSolaris\Prompts\Presets\GenerationPreset;
use Statikbe\FilamentSolaris\Support\UserInput;

it('includes user input data in the built prompt', function () {
    $builder = new InlinePromptBuilder;

    $prompt = $builder->build(
        instruction: 'Summarize.',
        sourceData: ['body' => 'Content'],
        factories: ['summary' => TextFactory::make(Textarea::make('summary'))],
        userInput: ['user_instructions' => 'Focus on the financial aspects'],
    );

    expect($prompt)->toContain('Focus on the financial aspects')
        ->toContain('Additional User Instructions');
});

it('omits individual empty user input values from prompt', function () {
    $builder = new InlinePromptBuilder;

    $prompt = $builder->build(
        instruction: 'Summarize.',
        sourceData: ['body' => 'Content'],
        factories: ['summary' => TextFactory::make(Textarea::make('summary'))],
        userInput: ['user_instructions' => ''],
    );

    // The section header appears (array is non-empty), but the empty value
    // is filtered out by the @if(filled($value)) check in the Blade template.
    expect($prompt)->not->toContain('User Instructions:');
});

it('handles multiple user input fields', function () {
    $builder = new InlinePromptBuilder;

    $prompt = $builder->build(
        instruction: 'Summarize.',
        sourceData: ['body' => 'Content'],
        factories: ['summary' => TextFactory::make(Textarea::make('summary'))],
        userInput: [
            'tone' => 'formal',
            'audience' => 'developers',
        ],
    );

    expect($prompt)
        ->toContain('formal')
        ->toContain('developers')
        ->toContain('Additional User Instructions');
});

it('builds user input form schema from prompt-based UserInput', function () {
    $userInput = UserInput::make()
        ->prompt('Custom instructions')
        ->placeholder('Type here...');

    $schema = $userInput->toFormSchema();

    expect($schema)->toHaveCount(1)
        ->and($schema[0])->toBeInstanceOf(Textarea::class)
        ->and($schema[0]->getName())->toBe('user_instructions');
});

it('builds user input form schema with custom fields', function () {
    $userInput = UserInput::make()
        ->fields([
            Textarea::make('notes')->label('Notes'),
            TextInput::make('context')->label('Context'),
        ]);

    $schema = $userInput->toFormSchema();

    expect($schema)->toHaveCount(2)
        ->and($schema[0]->getName())->toBe('notes')
        ->and($schema[1]->getName())->toBe('context');
});

it('withDefaultUserInput sets user input from preset', function () {
    $preset = GenerationPreset::make();
    $defaultInput = $preset->defaultUserInput();

    expect($defaultInput)->not->toBeNull();

    $schema = $defaultInput->toFormSchema();
    expect($schema)->toHaveCount(1)
        ->and($schema[0])->toBeInstanceOf(Textarea::class);
});

it('resolves withDefaultUserInput when called after preset', function () {
    $action = AiFormAction::make('generate')
        ->preset(GenerationPreset::make())
        ->withDefaultUserInput();

    expect($action->hasUserInput())->toBeTrue()
        ->and($action->getUserInput())->not->toBeNull();
});

it('resolves withDefaultUserInput when called before preset (deferred)', function () {
    // Regression: withDefaultUserInput() used to read $this->promptBuilder
    // eagerly, so calling it before ->preset() silently no-op'd. Resolution
    // is now deferred to getUserInput(), making call order irrelevant.
    $action = AiFormAction::make('generate')
        ->withDefaultUserInput()
        ->preset(GenerationPreset::make());

    expect($action->hasUserInput())->toBeTrue()
        ->and($action->getUserInput())->not->toBeNull();
});

it('lets an explicit userInput() win over the preset default regardless of order', function () {
    $explicit = UserInput::make()->fields([TextInput::make('context')]);

    $action = AiFormAction::make('generate')
        ->withDefaultUserInput()
        ->userInput($explicit)
        ->preset(GenerationPreset::make());

    expect($action->getUserInput())->toBe($explicit);
});

it('reports no user input when withDefaultUserInput is not opted in', function () {
    $action = AiFormAction::make('generate')
        ->preset(GenerationPreset::make());

    expect($action->hasUserInput())->toBeFalse()
        ->and($action->getUserInput())->toBeNull();
});
