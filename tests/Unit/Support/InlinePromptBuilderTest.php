<?php

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Statikbe\FilamentSolaris\Factories\SelectFactory;
use Statikbe\FilamentSolaris\Factories\TextFactory;
use Statikbe\FilamentSolaris\Prompts\InlinePromptBuilder;

it('renders the base wrapper with instruction', function () {
    $builder = new InlinePromptBuilder;

    $prompt = $builder->build(
        instruction: 'Summarize this article.',
        sourceData: ['body' => 'Article content here.'],
        factories: ['summary' => TextFactory::make(Textarea::make('summary'))],
    );

    expect($prompt)
        ->toContain('You are an AI assistant')
        ->toContain('Summarize this article.')
        ->toContain('Article content here.');
});

it('includes source data in the prompt', function () {
    $builder = new InlinePromptBuilder;

    $prompt = $builder->build(
        instruction: 'Classify this.',
        sourceData: ['title' => 'Test Title', 'body' => 'Test Body'],
        factories: ['category' => SelectFactory::make(Select::make('category')->options(['news' => 'News']))],
    );

    expect($prompt)
        ->toContain('Test Title')
        ->toContain('Test Body');
});

it('includes user input when provided', function () {
    $builder = new InlinePromptBuilder;

    $prompt = $builder->build(
        instruction: 'Summarize.',
        sourceData: ['body' => 'Content'],
        factories: ['summary' => TextFactory::make(Textarea::make('summary'))],
        userInput: ['user_instructions' => 'Focus on the financial aspects'],
    );

    expect($prompt)->toContain('Focus on the financial aspects');
});

it('omits user input section when empty', function () {
    $builder = new InlinePromptBuilder;

    $prompt = $builder->build(
        instruction: 'Summarize.',
        sourceData: ['body' => 'Content'],
        factories: ['summary' => TextFactory::make(Textarea::make('summary'))],
        userInput: [],
    );

    expect($prompt)->not->toContain('Additional User Instructions');
});

it('includes locale hint for non-English locales', function () {
    $builder = new InlinePromptBuilder;

    $prompt = $builder->build(
        instruction: 'Summarize.',
        sourceData: ['body' => 'Content'],
        factories: ['summary' => TextFactory::make(Textarea::make('summary'))],
        locale: 'nl',
    );

    expect($prompt)->toContain('Dutch');
});

it('omits locale hint for English', function () {
    $builder = new InlinePromptBuilder;

    $prompt = $builder->build(
        instruction: 'Summarize.',
        sourceData: ['body' => 'Content'],
        factories: ['summary' => TextFactory::make(Textarea::make('summary'))],
        locale: 'en',
    );

    expect($prompt)->not->toContain('Language');
});

it('does not include JSON schema in the prompt text', function () {
    $builder = new InlinePromptBuilder;

    $prompt = $builder->build(
        instruction: 'Classify.',
        sourceData: ['body' => 'Content'],
        factories: [
            'category' => SelectFactory::make(Select::make('category')->options(['news' => 'News', 'opinion' => 'Opinion'])),
        ],
    );

    expect($prompt)->not->toContain('Response Format');
});
