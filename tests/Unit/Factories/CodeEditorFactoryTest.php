<?php

use Filament\Forms\Components\CodeEditor;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Statikbe\FilamentSolaris\Factories\TextFactory;

it('generates string schema for code editor', function () {
    $editor = CodeEditor::make('code');
    $factory = TextFactory::make($editor);
    $schema = $factory->responseSchema(new JsonSchemaTypeFactory)->toArray();

    expect($schema['type'])->toBe('string');
});

it('returns code string as-is in toFormValue', function () {
    $editor = CodeEditor::make('code');
    $factory = TextFactory::make($editor);

    $code = "<?php\necho 'hello';";
    expect($factory->toFormValue($code))->toBe($code);
});

it('returns code string in toPromptContext', function () {
    $editor = CodeEditor::make('code');
    $factory = TextFactory::make($editor);

    $code = 'function foo() { return 42; }';
    expect($factory->toPromptContext($code))->toBe($code);
});
