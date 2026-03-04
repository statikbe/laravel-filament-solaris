<?php

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Statikbe\FilamentSolaris\Factories\BooleanFactory;
use Statikbe\FilamentSolaris\Factories\SelectFactory;
use Statikbe\FilamentSolaris\Factories\TextFactory;
use Statikbe\FilamentSolaris\Support\ComponentFactoryResolver;

it('resolves a Select component to SelectFactory', function () {
    $resolver = new ComponentFactoryResolver;
    $components = [
        Select::make('category')->options(['a' => 'A']),
    ];

    $factory = $resolver->resolve($components, 'category');

    expect($factory)->toBeInstanceOf(SelectFactory::class);
});

it('resolves a TextInput component to TextFactory', function () {
    $resolver = new ComponentFactoryResolver;
    $components = [
        TextInput::make('title'),
    ];

    $factory = $resolver->resolve($components, 'title');

    expect($factory)->toBeInstanceOf(TextFactory::class);
});

it('resolves a Textarea component to TextFactory', function () {
    $resolver = new ComponentFactoryResolver;
    $components = [
        Textarea::make('body'),
    ];

    $factory = $resolver->resolve($components, 'body');

    expect($factory)->toBeInstanceOf(TextFactory::class);
});

it('resolves a Toggle component to BooleanFactory', function () {
    $resolver = new ComponentFactoryResolver;
    $components = [
        Toggle::make('active'),
    ];

    $factory = $resolver->resolve($components, 'active');

    expect($factory)->toBeInstanceOf(BooleanFactory::class);
});

it('throws RuntimeException for missing component', function () {
    $resolver = new ComponentFactoryResolver;
    $components = [
        TextInput::make('title'),
    ];

    $resolver->resolve($components, 'nonexistent');
})->throws(RuntimeException::class, "Component 'nonexistent' not found in form.");

it('throws RuntimeException for unmapped component type', function () {
    // Create a custom component that is not mapped
    $customComponent = new class extends \Filament\Schemas\Components\Component
    {
        protected string $view = 'test';

        public function getName(): string
        {
            return 'custom';
        }
    };

    $resolver = new ComponentFactoryResolver;

    $resolver->resolve([$customComponent], 'custom');
})->throws(RuntimeException::class, 'No AI factory registered for');

it('resolves many fields at once', function () {
    $resolver = new ComponentFactoryResolver;
    $components = [
        Select::make('category')->options(['a' => 'A']),
        Textarea::make('body'),
        Toggle::make('active'),
    ];

    $factories = $resolver->resolveMany($components, ['category', 'body', 'active']);

    expect($factories)->toHaveCount(3)
        ->and($factories['category'])->toBeInstanceOf(SelectFactory::class)
        ->and($factories['body'])->toBeInstanceOf(TextFactory::class)
        ->and($factories['active'])->toBeInstanceOf(BooleanFactory::class);
});

it('passes scope to factory', function () {
    $resolver = new ComponentFactoryResolver;
    $scope = fn ($q) => $q->where('active', true);
    $components = [
        Select::make('category')->options(['a' => 'A']),
    ];

    $factory = $resolver->resolve($components, 'category', $scope);

    expect($factory->getScope())->toBe($scope);
});
