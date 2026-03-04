<?php

use Filament\Forms\Components\CheckboxList;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Statikbe\FilamentSolaris\Factories\CheckboxListFactory;

it('generates array schema with enum for checkbox list options', function () {
    $checkboxList = CheckboxList::make('tags')->options([
        'php' => 'PHP',
        'laravel' => 'Laravel',
        'filament' => 'Filament',
    ]);

    $factory = CheckboxListFactory::make($checkboxList);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['type'])->toBe('string')
        ->and($schema['items']['enum'])->toBe(['php', 'laravel', 'filament'])
        ->and($schema['description'])->toContain('Options:');
});

it('generates schema without enum when options exceed threshold', function () {
    $options = [];
    for ($i = 0; $i < 150; $i++) {
        $options["tag_{$i}"] = "Tag {$i}";
    }

    $checkboxList = CheckboxList::make('tags')->options($options);
    $factory = CheckboxListFactory::make($checkboxList);

    config()->set('filament-solaris.max_options', 100);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['type'])->toBe('array')
        ->and($schema['items'])->toBe(['type' => 'string'])
        ->and($schema['description'])->toContain('150 available options');
});

it('returns array of keys as-is in toFormValue', function () {
    $checkboxList = CheckboxList::make('tags')->options([
        'php' => 'PHP',
        'laravel' => 'Laravel',
    ]);

    $factory = CheckboxListFactory::make($checkboxList);

    expect($factory->toFormValue(['php', 'laravel']))->toBe(['php', 'laravel']);
});

it('wraps single string in array in toFormValue', function () {
    $checkboxList = CheckboxList::make('tags')->options([
        'php' => 'PHP',
    ]);

    $factory = CheckboxListFactory::make($checkboxList);

    expect($factory->toFormValue('php'))->toBe(['php']);
});

it('resolves labels to keys in toFormValue', function () {
    $checkboxList = CheckboxList::make('tags')->options([
        'php' => 'PHP',
        'laravel' => 'Laravel',
    ]);

    $factory = CheckboxListFactory::make($checkboxList);

    expect($factory->toFormValue(['PHP', 'Laravel']))->toBe(['php', 'laravel']);
});

it('filters out unresolvable values in toFormValue', function () {
    $checkboxList = CheckboxList::make('tags')->options([
        'php' => 'PHP',
        'laravel' => 'Laravel',
    ]);

    $factory = CheckboxListFactory::make($checkboxList);

    expect($factory->toFormValue(['PHP', 'unknown_tag']))->toBe(['php']);
});

it('returns comma-separated labels in toPromptContext', function () {
    $checkboxList = CheckboxList::make('tags')->options([
        'php' => 'PHP',
        'laravel' => 'Laravel',
    ]);

    $factory = CheckboxListFactory::make($checkboxList);

    expect($factory->toPromptContext(['php', 'laravel']))->toBe('PHP, Laravel');
});

it('returns "None selected" for empty array in toPromptContext', function () {
    $checkboxList = CheckboxList::make('tags')->options([
        'php' => 'PHP',
    ]);

    $factory = CheckboxListFactory::make($checkboxList);

    expect($factory->toPromptContext([]))->toBe('None selected');
});

it('returns "None selected" for null in toPromptContext', function () {
    $checkboxList = CheckboxList::make('tags')->options([
        'php' => 'PHP',
    ]);

    $factory = CheckboxListFactory::make($checkboxList);

    expect($factory->toPromptContext(null))->toBe('None selected');
});

it('returns empty array for null in toFormValue', function () {
    $checkboxList = CheckboxList::make('tags')->options([
        'php' => 'PHP',
    ]);

    $factory = CheckboxListFactory::make($checkboxList);

    expect($factory->toFormValue(null))->toBe([]);
});

it('appends hint to schema description when set', function () {
    $checkboxList = CheckboxList::make('tags')->options([
        'php' => 'PHP',
        'laravel' => 'Laravel',
    ]);

    $factory = CheckboxListFactory::make($checkboxList);
    $factory->hint('Select all that apply.');
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['description'])->toContain('Options:')
        ->and($schema['description'])->toContain('Select all that apply.');
});

it('does not append hint when hint is null', function () {
    $checkboxList = CheckboxList::make('tags')->options([
        'php' => 'PHP',
        'laravel' => 'Laravel',
    ]);

    $factory = CheckboxListFactory::make($checkboxList);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['description'])->toBe('Options: php (PHP), laravel (Laravel)');
});
