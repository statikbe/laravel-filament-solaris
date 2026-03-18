<?php

use Filament\Forms\Components\MarkdownEditor;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Statikbe\FilamentSolaris\Factories\MarkdownFactory;

it('generates string schema with markdown instruction', function () {
    $editor = MarkdownEditor::make('body');
    $factory = MarkdownFactory::make($editor);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['type'])->toBe('string')
        ->and($schema['description'])->toContain('Markdown');
});

it('includes formatting hints in description', function () {
    $editor = MarkdownEditor::make('body');
    $factory = MarkdownFactory::make($editor);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['description'])
        ->toContain('**text**')
        ->toContain('*text*')
        ->toContain('headings');
});

it('returns markdown string as-is in toFormValue', function () {
    $editor = MarkdownEditor::make('body');
    $factory = MarkdownFactory::make($editor);

    $md = "# Hello\n\nThis is **bold** and *italic*.";
    expect($factory->toFormValue($md))->toBe($md);
});

it('returns null for null in toFormValue', function () {
    $editor = MarkdownEditor::make('body');
    $factory = MarkdownFactory::make($editor);

    expect($factory->toFormValue(null))->toBeNull();
});

it('converts array to json string in toFormValue', function () {
    $editor = MarkdownEditor::make('body');
    $factory = MarkdownFactory::make($editor);

    expect($factory->toFormValue(['key' => 'value']))->toBe('{"key":"value"}');
});

it('returns markdown string in toPromptContext', function () {
    $editor = MarkdownEditor::make('body');
    $factory = MarkdownFactory::make($editor);

    $md = "## Section\n\n- item 1\n- item 2";
    expect($factory->toPromptContext($md))->toBe($md);
});

it('returns empty string for null in toPromptContext', function () {
    $editor = MarkdownEditor::make('body');
    $factory = MarkdownFactory::make($editor);

    expect($factory->toPromptContext(null))->toBe('');
});

it('appends hint to schema description', function () {
    $editor = MarkdownEditor::make('body');
    $factory = MarkdownFactory::make($editor);
    $factory->hint('Keep it concise.');
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['description'])
        ->toContain('Markdown')
        ->toContain('Keep it concise.');
});
