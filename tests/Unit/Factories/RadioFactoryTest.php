<?php

use Filament\Forms\Components\Radio;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Statikbe\FilamentSolaris\Factories\RadioFactory;

it('generates enum schema for radio options', function () {
    $radio = Radio::make('priority')->options([
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
    ]);

    $factory = RadioFactory::make($radio);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['type'])->toBe('string')
        ->and($schema['enum'])->toBe(['low', 'medium', 'high']);
});

it('resolves label to key in toFormValue', function () {
    $radio = Radio::make('priority')->options([
        'low' => 'Low',
        'medium' => 'Medium',
    ]);

    $factory = RadioFactory::make($radio);

    expect($factory->toFormValue('Medium'))->toBe('medium');
});

it('returns label in toPromptContext', function () {
    $radio = Radio::make('priority')->options([
        'low' => 'Low',
        'medium' => 'Medium',
    ]);

    $factory = RadioFactory::make($radio);

    expect($factory->toPromptContext('medium'))->toBe('Medium');
});
