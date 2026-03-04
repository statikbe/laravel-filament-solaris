<?php

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Toggle;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Statikbe\FilamentSolaris\Factories\BooleanFactory;

it('generates boolean schema', function () {
    $toggle = Toggle::make('active');
    $factory = BooleanFactory::make($toggle);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['type'])->toBe('boolean')
        ->and($schema['description'])->toBe('true or false');
});

it('returns boolean true as-is in toFormValue', function () {
    $toggle = Toggle::make('active');
    $factory = BooleanFactory::make($toggle);

    expect($factory->toFormValue(true))->toBeTrue();
});

it('returns boolean false as-is in toFormValue', function () {
    $toggle = Toggle::make('active');
    $factory = BooleanFactory::make($toggle);

    expect($factory->toFormValue(false))->toBeFalse();
});

it('parses truthy strings in toFormValue', function () {
    $toggle = Toggle::make('active');
    $factory = BooleanFactory::make($toggle);

    expect($factory->toFormValue('true'))->toBeTrue()
        ->and($factory->toFormValue('yes'))->toBeTrue()
        ->and($factory->toFormValue('1'))->toBeTrue();
});

it('parses falsy strings in toFormValue', function () {
    $toggle = Toggle::make('active');
    $factory = BooleanFactory::make($toggle);

    expect($factory->toFormValue('false'))->toBeFalse()
        ->and($factory->toFormValue('no'))->toBeFalse()
        ->and($factory->toFormValue('0'))->toBeFalse();
});

it('parses integer 1 as true in toFormValue', function () {
    $toggle = Toggle::make('active');
    $factory = BooleanFactory::make($toggle);

    expect($factory->toFormValue(1))->toBeTrue();
});

it('parses integer 0 as false in toFormValue', function () {
    $toggle = Toggle::make('active');
    $factory = BooleanFactory::make($toggle);

    expect($factory->toFormValue(0))->toBeFalse();
});

it('defaults to false for uninterpretable values in toFormValue', function () {
    $toggle = Toggle::make('active');
    $factory = BooleanFactory::make($toggle);

    expect($factory->toFormValue('maybe'))->toBeFalse();
});

it('returns "Yes" for true in toPromptContext', function () {
    $toggle = Toggle::make('active');
    $factory = BooleanFactory::make($toggle);

    expect($factory->toPromptContext(true))->toBe('Yes');
});

it('returns "No" for false in toPromptContext', function () {
    $toggle = Toggle::make('active');
    $factory = BooleanFactory::make($toggle);

    expect($factory->toPromptContext(false))->toBe('No');
});

it('returns "Not set" for null in toPromptContext', function () {
    $toggle = Toggle::make('active');
    $factory = BooleanFactory::make($toggle);

    expect($factory->toPromptContext(null))->toBe('Not set');
});

it('works with Checkbox component', function () {
    $checkbox = Checkbox::make('agree');
    $factory = BooleanFactory::make($checkbox);

    expect($factory->toFormValue(true))->toBeTrue()
        ->and($factory->toPromptContext(true))->toBe('Yes');
});

it('appends hint to schema description when set', function () {
    $toggle = Toggle::make('active');
    $factory = BooleanFactory::make($toggle);
    $factory->hint('True means the item is published.');
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['description'])->toBe('true or false True means the item is published.');
});

it('does not append hint when hint is null', function () {
    $toggle = Toggle::make('active');
    $factory = BooleanFactory::make($toggle);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['description'])->toBe('true or false');
});
