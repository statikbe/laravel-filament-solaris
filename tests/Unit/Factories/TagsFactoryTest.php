<?php

use Filament\Forms\Components\TagsInput;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Statikbe\FilamentSolaris\Factories\TagsFactory;

it('generates array schema', function () {
    $tags = TagsInput::make('tags');
    $factory = TagsFactory::make($tags);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['type'])->toBe('string');
});

it('includes suggestions in schema description', function () {
    $tags = TagsInput::make('tags')->suggestions(['php', 'laravel', 'filament']);
    $factory = TagsFactory::make($tags);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['description'])
        ->toContain('php')
        ->toContain('laravel')
        ->toContain('filament');
});

it('returns array as-is in toFormValue', function () {
    $tags = TagsInput::make('tags');
    $factory = TagsFactory::make($tags);

    expect($factory->toFormValue(['php', 'laravel']))->toBe(['php', 'laravel']);
});

it('splits comma-separated string in toFormValue', function () {
    $tags = TagsInput::make('tags');
    $factory = TagsFactory::make($tags);

    expect($factory->toFormValue('php, laravel, filament'))->toBe(['php', 'laravel', 'filament']);
});

it('wraps single value in array in toFormValue', function () {
    $tags = TagsInput::make('tags');
    $factory = TagsFactory::make($tags);

    expect($factory->toFormValue(42))->toBe(['42']);
});

it('returns empty array for null in toFormValue', function () {
    $tags = TagsInput::make('tags');
    $factory = TagsFactory::make($tags);

    expect($factory->toFormValue(null))->toBe([]);
});

it('casts array values to strings in toFormValue', function () {
    $tags = TagsInput::make('tags');
    $factory = TagsFactory::make($tags);

    expect($factory->toFormValue([1, 2, 3]))->toBe(['1', '2', '3']);
});

it('joins tags with comma in toPromptContext', function () {
    $tags = TagsInput::make('tags');
    $factory = TagsFactory::make($tags);

    expect($factory->toPromptContext(['php', 'laravel']))->toBe('php, laravel');
});

it('returns empty string for null in toPromptContext', function () {
    $tags = TagsInput::make('tags');
    $factory = TagsFactory::make($tags);

    expect($factory->toPromptContext(null))->toBe('');
});

it('returns empty string for empty array in toPromptContext', function () {
    $tags = TagsInput::make('tags');
    $factory = TagsFactory::make($tags);

    expect($factory->toPromptContext([]))->toBe('');
});

it('appends hint to schema description', function () {
    $tags = TagsInput::make('tags');
    $factory = TagsFactory::make($tags);
    $factory->hint('Use lowercase tags only.');
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['description'])->toContain('Use lowercase tags only.');
});
