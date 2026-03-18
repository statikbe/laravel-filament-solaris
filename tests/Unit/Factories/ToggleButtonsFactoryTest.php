<?php

use Filament\Forms\Components\ToggleButtons;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Statikbe\FilamentSolaris\Factories\SelectFactory;

it('generates enum schema for toggle buttons options', function () {
    $buttons = ToggleButtons::make('status')->options([
        'draft' => 'Draft',
        'published' => 'Published',
        'archived' => 'Archived',
    ]);
    $factory = SelectFactory::make($buttons);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['type'])->toBe('string')
        ->and($schema['enum'])->toBe(['draft', 'published', 'archived']);
});

it('resolves option key from label in toFormValue', function () {
    $buttons = ToggleButtons::make('status')->options([
        'draft' => 'Draft',
        'published' => 'Published',
    ]);
    $factory = SelectFactory::make($buttons);

    expect($factory->toFormValue('Published'))->toBe('published');
});

it('returns exact key match in toFormValue', function () {
    $buttons = ToggleButtons::make('status')->options([
        'draft' => 'Draft',
        'published' => 'Published',
    ]);
    $factory = SelectFactory::make($buttons);

    expect($factory->toFormValue('draft'))->toBe('draft');
});

it('returns label for key in toPromptContext', function () {
    $buttons = ToggleButtons::make('status')->options([
        'draft' => 'Draft',
        'published' => 'Published',
    ]);
    $factory = SelectFactory::make($buttons);

    expect($factory->toPromptContext('published'))->toBe('Published');
});

it('supports multiple mode', function () {
    $buttons = ToggleButtons::make('roles')->multiple()->options([
        'admin' => 'Admin',
        'editor' => 'Editor',
        'viewer' => 'Viewer',
    ]);
    $factory = SelectFactory::make($buttons);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['enum'])->toBe(['admin', 'editor', 'viewer']);
});

it('resolves multiple values in toFormValue', function () {
    $buttons = ToggleButtons::make('roles')->multiple()->options([
        'admin' => 'Admin',
        'editor' => 'Editor',
    ]);
    $factory = SelectFactory::make($buttons);

    expect($factory->toFormValue(['Admin', 'Editor']))->toBe(['admin', 'editor']);
});
