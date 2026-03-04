<?php

use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\View;
use Statikbe\FilamentSolaris\Factories\TextFactory;
use Statikbe\FilamentSolaris\Prompts\ViewPromptBuilder;

it('renders a Blade view with standard variables', function () {
    // Register an inline view for testing
    View::addNamespace('test', __DIR__.'/../../../resources/test-views');

    // Create a temp view file
    $viewDir = __DIR__.'/../../../resources/test-views';
    if (! is_dir($viewDir)) {
        mkdir($viewDir, 0755, true);
    }
    file_put_contents($viewDir.'/test-prompt.blade.php', 'Instruction: Test | Body: {{ $sourceData["body"] }}');

    $builder = new ViewPromptBuilder;

    $prompt = $builder->build(
        instruction: 'test::test-prompt',
        sourceData: ['body' => 'Hello World'],
        factories: ['summary' => TextFactory::make(Textarea::make('summary'))],
    );

    expect($prompt)
        ->toContain('Instruction: Test')
        ->toContain('Hello World');

    // Cleanup
    unlink($viewDir.'/test-prompt.blade.php');
    rmdir($viewDir);
});

it('renders a View instance with variables', function () {
    $viewContent = 'Custom prompt: {{ $sourceData["title"] }}';
    $tempDir = __DIR__.'/../../../resources/test-views-2';
    if (! is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    file_put_contents($tempDir.'/custom.blade.php', $viewContent);

    View::addNamespace('test2', $tempDir);

    $builder = new ViewPromptBuilder;

    $prompt = $builder->build(
        instruction: view('test2::custom'),
        sourceData: ['title' => 'My Article'],
        factories: ['summary' => TextFactory::make(Textarea::make('summary'))],
    );

    expect($prompt)->toContain('Custom prompt: My Article');

    // Cleanup
    unlink($tempDir.'/custom.blade.php');
    rmdir($tempDir);
});
