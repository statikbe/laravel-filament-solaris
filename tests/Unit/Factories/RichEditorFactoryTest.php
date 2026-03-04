<?php

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Statikbe\FilamentSolaris\Factories\RichEditorFactory;

it('generates string schema with allowed HTML tags in description', function () {
    $editor = RichEditor::make('body');
    $factory = RichEditorFactory::make($editor);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['type'])->toBe('string')
        ->and($schema['description'])->toContain('<p>')
        ->and($schema['description'])->toContain('<strong>')
        ->and($schema['description'])->toContain('<ul>')
        ->and($schema['description'])->toContain('<a href="...">')
        ->and($schema['description'])->toContain('HTML content');
});

it('includes maxLength in schema description when set', function () {
    $editor = RichEditor::make('body')->maxLength(500);
    $factory = RichEditorFactory::make($editor);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['description'])->toContain('500');
});

it('converts HTML to TipTap JSON document in toFormValue', function () {
    $editor = RichEditor::make('body');
    $factory = RichEditorFactory::make($editor);

    $result = $factory->toFormValue('<p>Hello <strong>world</strong></p>');

    expect($result)->toBeArray()
        ->and($result['type'])->toBe('doc')
        ->and($result['content'])->toBeArray()
        ->and($result['content'])->not->toBeEmpty();
});

it('wraps plain text in paragraph nodes in toFormValue', function () {
    $editor = RichEditor::make('body');
    $factory = RichEditorFactory::make($editor);

    $result = $factory->toFormValue('Hello world');

    expect($result)->toBeArray()
        ->and($result['type'])->toBe('doc')
        ->and($result['content'])->toBeArray()
        ->and($result['content'][0]['type'])->toBe('paragraph');
});

it('returns empty document for null in toFormValue', function () {
    $editor = RichEditor::make('body');
    $factory = RichEditorFactory::make($editor);

    $result = $factory->toFormValue(null);

    expect($result)->toBe(['type' => 'doc', 'content' => []]);
});

it('handles non-string values in toFormValue', function () {
    $editor = RichEditor::make('body');
    $factory = RichEditorFactory::make($editor);

    $result = $factory->toFormValue(42);

    expect($result)->toBeArray()
        ->and($result['type'])->toBe('doc')
        ->and($result['content'])->toBeArray()
        ->and($result['content'][0]['type'])->toBe('paragraph');
});

it('handles array values in toFormValue', function () {
    $editor = RichEditor::make('body');
    $factory = RichEditorFactory::make($editor);

    $result = $factory->toFormValue(['item1', 'item2']);

    expect($result)->toBeArray()
        ->and($result['type'])->toBe('doc')
        ->and($result['content'])->toBeArray();
});

it('extracts plain text from TipTap JSON in toPromptContext', function () {
    $editor = RichEditor::make('body');
    $factory = RichEditorFactory::make($editor);

    // Build a TipTap document via the renderer directly
    $tiptapDoc = RichContentRenderer::make()
        ->getEditor()
        ->setContent('<p>Hello world</p>')
        ->getDocument();

    $result = $factory->toPromptContext($tiptapDoc);

    expect($result)->toBeString()
        ->and($result)->toContain('Hello world');
});

it('strips HTML from string values in toPromptContext', function () {
    $editor = RichEditor::make('body');
    $factory = RichEditorFactory::make($editor);

    $result = $factory->toPromptContext('<p>Hello <strong>world</strong></p>');

    expect($result)->toBe('Hello world');
});

it('returns empty string for null in toPromptContext', function () {
    $editor = RichEditor::make('body');
    $factory = RichEditorFactory::make($editor);

    expect($factory->toPromptContext(null))->toBe('');
});

it('returns empty string for empty array in toPromptContext', function () {
    $editor = RichEditor::make('body');
    $factory = RichEditorFactory::make($editor);

    expect($factory->toPromptContext([]))->toBe('');
});

it('preserves paragraph structure in text extraction', function () {
    $editor = RichEditor::make('body');
    $factory = RichEditorFactory::make($editor);

    $tiptapDoc = RichContentRenderer::make()
        ->getEditor()
        ->setContent('<p>First paragraph</p><p>Second paragraph</p>')
        ->getDocument();

    $result = $factory->toPromptContext($tiptapDoc);

    expect($result)->toContain('First paragraph')
        ->and($result)->toContain('Second paragraph');
});

it('appends hint to schema description when set', function () {
    $editor = RichEditor::make('body');
    $factory = RichEditorFactory::make($editor);
    $factory->hint('Use short paragraphs.');
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['description'])->toContain('HTML content')
        ->and($schema['description'])->toContain('Use short paragraphs.');
});

it('does not append hint when hint is null', function () {
    $editor = RichEditor::make('body');
    $factory = RichEditorFactory::make($editor);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['description'])->toContain('HTML content')
        ->and($schema['description'])->not->toContain('Use short');
});

it('appends hint alongside maxLength description', function () {
    $editor = RichEditor::make('body')->maxLength(500);
    $factory = RichEditorFactory::make($editor);
    $factory->hint('Keep it concise.');
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['description'])->toContain('500 characters')
        ->and($schema['description'])->toContain('Keep it concise.');
});
