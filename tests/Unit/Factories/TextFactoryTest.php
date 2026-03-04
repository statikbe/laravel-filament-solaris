<?php

use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Statikbe\FilamentSolaris\Factories\TextFactory;

it('generates string schema for text components', function () {
    $textarea = Textarea::make('body');
    $factory = TextFactory::make($textarea);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['type'])->toBe('string');
});

it('includes maxLength in schema when set', function () {
    $input = TextInput::make('title')->maxLength(100);
    $factory = TextFactory::make($input);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['type'])->toBe('string')
        ->and($schema['maxLength'])->toBe(100)
        ->and($schema['description'])->toContain('100');
});

it('returns string as-is in toFormValue', function () {
    $textarea = Textarea::make('body');
    $factory = TextFactory::make($textarea);

    expect($factory->toFormValue('Hello world'))->toBe('Hello world');
});

it('casts non-string scalars to string in toFormValue', function () {
    $textarea = Textarea::make('body');
    $factory = TextFactory::make($textarea);

    expect($factory->toFormValue(42))->toBe('42')
        ->and($factory->toFormValue(3.14))->toBe('3.14');
});

it('json_encodes arrays in toFormValue', function () {
    $textarea = Textarea::make('body');
    $factory = TextFactory::make($textarea);

    expect($factory->toFormValue(['a', 'b']))->toBe('["a","b"]');
});

it('returns text as-is for MarkdownEditor', function () {
    $editor = MarkdownEditor::make('body');
    $factory = TextFactory::make($editor);

    expect($factory->toFormValue('# Heading'))->toBe('# Heading');
});

it('returns string as-is in toPromptContext', function () {
    $textarea = Textarea::make('body');
    $factory = TextFactory::make($textarea);

    expect($factory->toPromptContext('Hello world'))->toBe('Hello world');
});

it('returns empty string for null in toPromptContext', function () {
    $textarea = Textarea::make('body');
    $factory = TextFactory::make($textarea);

    expect($factory->toPromptContext(null))->toBe('');
});

it('handles null in toFormValue', function () {
    $textarea = Textarea::make('body');
    $factory = TextFactory::make($textarea);

    expect($factory->toFormValue(null))->toBeNull();
});

it('appends hint to schema description when set', function () {
    $textarea = Textarea::make('body');
    $factory = TextFactory::make($textarea);
    $factory->hint('Approximately 100 words.');
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['description'])->toBe('Approximately 100 words.');
});

it('appends hint alongside maxLength description', function () {
    $input = TextInput::make('title')->maxLength(100);
    $factory = TextFactory::make($input);
    $factory->hint('Use a professional tone.');
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['description'])->toBe('Maximum 100 characters. Use a professional tone.')
        ->and($schema['maxLength'])->toBe(100);
});

it('does not add description when no hint and no maxLength', function () {
    $textarea = Textarea::make('body');
    $factory = TextFactory::make($textarea);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema)->not->toHaveKey('description');
});
