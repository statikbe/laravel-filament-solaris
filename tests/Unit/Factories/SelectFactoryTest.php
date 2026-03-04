<?php

use Filament\Forms\Components\Select;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Statikbe\FilamentSolaris\Factories\SelectFactory;

it('generates enum schema for static select options', function () {
    $select = Select::make('category')->options([
        'news' => 'News',
        'opinion' => 'Opinion',
        'tech' => 'Technology',
    ]);

    $factory = SelectFactory::make($select);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['type'])->toBe('string')
        ->and($schema['enum'])->toBe(['news', 'opinion', 'tech'])
        ->and($schema['description'])->toContain('news (News)');
});

it('generates free-text schema when options exceed max threshold', function () {
    $options = [];
    for ($i = 0; $i < 150; $i++) {
        $options["opt_{$i}"] = "Option {$i}";
    }

    $select = Select::make('category')->options($options);
    $factory = SelectFactory::make($select);

    config()->set('filament-solaris.max_options', 100);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['type'])->toBe('string')
        ->and($schema)->not->toHaveKey('enum')
        ->and($schema['description'])->toContain('150 available options');
});

it('returns exact key match in toFormValue', function () {
    $select = Select::make('category')->options([
        'news' => 'News',
        'opinion' => 'Opinion',
    ]);

    $factory = SelectFactory::make($select);

    expect($factory->toFormValue('news'))->toBe('news');
});

it('resolves exact label to key in toFormValue', function () {
    $select = Select::make('category')->options([
        'news' => 'News',
        'opinion' => 'Opinion',
    ]);

    $factory = SelectFactory::make($select);

    expect($factory->toFormValue('News'))->toBe('news');
});

it('resolves case-insensitive label match in toFormValue', function () {
    $select = Select::make('category')->options([
        'news' => 'News',
        'opinion' => 'Opinion',
    ]);

    $factory = SelectFactory::make($select);

    expect($factory->toFormValue('news'))->toBe('news');
    expect($factory->toFormValue('OPINION'))->toBe('opinion');
});

it('resolves substring match in toFormValue', function () {
    $select = Select::make('category')->options([
        'breaking_news' => 'Breaking News Alert',
    ]);

    $factory = SelectFactory::make($select);

    expect($factory->toFormValue('breaking news'))->toBe('breaking_news');
});

it('resolves levenshtein match within distance 3 in toFormValue', function () {
    $select = Select::make('category')->options([
        'news' => 'News',
        'opinion' => 'Opinions',
    ]);

    $factory = SelectFactory::make($select);

    // "Opinon" has Levenshtein distance 2 from "Opinions"
    expect($factory->toFormValue('Opinon'))->toBe('opinion');
});

it('returns raw value when no match found in toFormValue', function () {
    $select = Select::make('category')->options([
        'news' => 'News',
    ]);

    $factory = SelectFactory::make($select);

    expect($factory->toFormValue('completely_different_value'))->toBe('completely_different_value');
});

it('returns label for valid key in toPromptContext', function () {
    $select = Select::make('category')->options([
        'news' => 'News',
        'opinion' => 'Opinion',
    ]);

    $factory = SelectFactory::make($select);

    expect($factory->toPromptContext('news'))->toBe('News');
});

it('returns value as-is for invalid key in toPromptContext', function () {
    $select = Select::make('category')->options([
        'news' => 'News',
    ]);

    $factory = SelectFactory::make($select);

    expect($factory->toPromptContext('unknown'))->toBe('unknown');
});

it('returns null as-is in toPromptContext', function () {
    $select = Select::make('category')->options(['news' => 'News']);
    $factory = SelectFactory::make($select);

    expect($factory->toPromptContext(null))->toBeNull();
});

it('handles null in toFormValue', function () {
    $select = Select::make('category')->options(['news' => 'News']);
    $factory = SelectFactory::make($select);

    expect($factory->toFormValue(null))->toBeNull();
});

it('handles empty options in toFormValue', function () {
    $select = Select::make('category');
    $factory = SelectFactory::make($select);

    expect($factory->toFormValue('some_value'))->toBe('some_value');
});

it('appends hint to schema description when set', function () {
    $select = Select::make('category')->options([
        'news' => 'News',
        'opinion' => 'Opinion',
    ]);

    $factory = SelectFactory::make($select);
    $factory->hint('Pick the most relevant category.');
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['description'])->toContain('Options:')
        ->and($schema['description'])->toContain('Pick the most relevant category.');
});

it('does not append hint when hint is null', function () {
    $select = Select::make('category')->options([
        'news' => 'News',
        'opinion' => 'Opinion',
    ]);

    $factory = SelectFactory::make($select);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['description'])->toContain('Options:')
        ->and($schema['description'])->not->toContain('Pick');
});

it('appends hint to large-options schema description', function () {
    $options = [];
    for ($i = 0; $i < 150; $i++) {
        $options["opt_{$i}"] = "Option {$i}";
    }

    $select = Select::make('category')->options($options);
    $factory = SelectFactory::make($select);
    $factory->hint('Choose the best match.');

    config()->set('filament-solaris.max_options', 100);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['description'])->toContain('150 available options')
        ->and($schema['description'])->toContain('Choose the best match.');
});

it('generates array schema for multiple select', function () {
    $select = Select::make('categories')->multiple()->options([
        'news' => 'News',
        'opinion' => 'Opinion',
        'tech' => 'Technology',
    ]);

    $factory = SelectFactory::make($select);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['type'])->toBe('string')
        ->and($schema['items']['enum'])->toBe(['news', 'opinion', 'tech'])
        ->and($schema['description'])->toContain('Options:');
});

it('generates array schema for multiple select with many options', function () {
    $options = [];
    for ($i = 0; $i < 150; $i++) {
        $options["opt_{$i}"] = "Option {$i}";
    }

    $select = Select::make('categories')->multiple()->options($options);
    $factory = SelectFactory::make($select);

    config()->set('filament-solaris.max_options', 100);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['type'])->toBe('array')
        ->and($schema['items'])->toBe(['type' => 'string'])
        ->and($schema['description'])->toContain('150 available options');
});

it('resolves array of values in toFormValue for multiple select', function () {
    $select = Select::make('categories')->multiple()->options([
        'news' => 'News',
        'opinion' => 'Opinion',
        'tech' => 'Technology',
    ]);

    $factory = SelectFactory::make($select);

    expect($factory->toFormValue(['news', 'tech']))->toBe(['news', 'tech']);
});

it('resolves array of labels to keys in toFormValue for multiple select', function () {
    $select = Select::make('categories')->multiple()->options([
        'news' => 'News',
        'opinion' => 'Opinion',
    ]);

    $factory = SelectFactory::make($select);

    expect($factory->toFormValue(['News', 'Opinion']))->toBe(['news', 'opinion']);
});

it('filters invalid values in toFormValue for multiple select', function () {
    $select = Select::make('categories')->multiple()->options([
        'news' => 'News',
        'opinion' => 'Opinion',
    ]);

    $factory = SelectFactory::make($select);

    expect($factory->toFormValue(['News', 'unknown']))->toBe(['news']);
});

it('wraps single string in array for multiple select toFormValue', function () {
    $select = Select::make('categories')->multiple()->options([
        'news' => 'News',
    ]);

    $factory = SelectFactory::make($select);

    expect($factory->toFormValue('news'))->toBe(['news']);
});

it('returns empty array for null in toFormValue for multiple select', function () {
    $select = Select::make('categories')->multiple()->options([
        'news' => 'News',
    ]);

    $factory = SelectFactory::make($select);

    expect($factory->toFormValue(null))->toBe([]);
});

it('returns comma-separated labels in toPromptContext for array', function () {
    $select = Select::make('categories')->multiple()->options([
        'news' => 'News',
        'opinion' => 'Opinion',
    ]);

    $factory = SelectFactory::make($select);

    expect($factory->toPromptContext(['news', 'opinion']))->toBe('News, Opinion');
});
