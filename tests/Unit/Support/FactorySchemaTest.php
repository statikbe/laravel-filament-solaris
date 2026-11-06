<?php

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Statikbe\FilamentSolaris\Factories\TextFactory;
use Statikbe\FilamentSolaris\Support\FactorySchema;

it('maps each factory to its response schema', function () {
    $factories = [
        'summary' => TextFactory::make(Textarea::make('summary')),
        'title' => TextFactory::make(TextInput::make('title')->maxLength(100)),
    ];
    $s = new JsonSchemaTypeFactory;

    $schema = FactorySchema::resolver($factories)($s);

    expect(array_keys($schema))->toBe(['summary', 'title']);

    foreach ($factories as $name => $factory) {
        expect($schema[$name])->toBeInstanceOf(Type::class)
            ->and($schema[$name]->toArray())->toBe($factory->responseSchema($s)->toArray());
    }
});

it('returns an empty schema for no factories', function () {
    expect(FactorySchema::resolver([])(new JsonSchemaTypeFactory))->toBe([]);
});
