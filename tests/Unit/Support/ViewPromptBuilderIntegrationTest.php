<?php

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\View;
use Statikbe\FilamentSolaris\Factories\SelectFactory;
use Statikbe\FilamentSolaris\Factories\TextFactory;
use Statikbe\FilamentSolaris\Prompts\ViewPromptBuilder;

beforeEach(function () {
    $this->viewDir = __DIR__.'/../../../resources/test-views-integration';
    if (! is_dir($this->viewDir)) {
        mkdir($this->viewDir, 0755, true);
    }
    View::addNamespace('test-int', $this->viewDir);
});

afterEach(function () {
    foreach (glob("{$this->viewDir}/*.php") as $file) {
        unlink($file);
    }
    if (is_dir($this->viewDir)) {
        rmdir($this->viewDir);
    }
});

it('passes all standard variables to a custom Blade view', function () {
    // Use {!! !!} for unescaped output so we can match exact strings
    file_put_contents(
        $this->viewDir.'/full-prompt.blade.php',
        implode("\n", [
            'Source: {!! json_encode($sourceData) !!}',
            'Locale: {{ $locale ?? "none" }}',
            'LocaleName: {{ $localeName ?? "none" }}',
            '@if(!empty($userInput))UserInput: {!! json_encode($userInput) !!}@endif',
        ])
    );

    $builder = new ViewPromptBuilder;

    $prompt = $builder->build(
        instruction: 'test-int::full-prompt',
        sourceData: ['title' => 'My Article', 'body' => 'Content here'],
        factories: [
            'summary' => TextFactory::make(Textarea::make('summary')),
            'category' => SelectFactory::make(Select::make('category')->options(['news' => 'News'])),
        ],
        locale: 'nl',
        userInput: ['tone' => 'formal'],
    );

    expect($prompt)
        ->toContain('My Article')
        ->toContain('Content here')
        ->toContain('Dutch')
        ->toContain('formal');
});

it('includes response schema as arrays in view data', function () {
    file_put_contents(
        $this->viewDir.'/schema-check.blade.php',
        '@foreach($responseSchema as $field => $schema){{ $field }}:{{ $schema["type"] }},@endforeach'
    );

    $builder = new ViewPromptBuilder;

    $prompt = $builder->build(
        instruction: 'test-int::schema-check',
        sourceData: ['body' => 'Content'],
        factories: [
            'summary' => TextFactory::make(Textarea::make('summary')),
        ],
    );

    expect($prompt)->toContain('summary:string');
});

it('passes factories to the view for custom introspection', function () {
    file_put_contents(
        $this->viewDir.'/factory-check.blade.php',
        '@foreach($factories as $field => $factory){{ $field }}:{{ class_basename($factory) }},@endforeach'
    );

    $builder = new ViewPromptBuilder;

    $prompt = $builder->build(
        instruction: 'test-int::factory-check',
        sourceData: ['body' => 'Content'],
        factories: [
            'category' => SelectFactory::make(Select::make('category')->options(['news' => 'News'])),
        ],
    );

    expect($prompt)->toContain('category:SelectFactory');
});
