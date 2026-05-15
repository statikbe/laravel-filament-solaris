<?php

use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\ImageGenerationAction;
use Statikbe\FilamentSolaris\Testing\ImageGenerationActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\ImageGenerationFormComponent;

beforeEach(function () {
    ImageGenerationActionFake::reset();
});

afterEach(function () {
    ImageGenerationActionFake::reset();
});

// ──────────────────────────────────────────────────────────────
//  Basic image generation
// ──────────────────────────────────────────────────────────────

it('generates image and sets path on target field', function () {
    ImageGenerationAction::fake('ai-images/generated.png');

    Livewire::test(ImageGenerationFormComponent::class)
        ->callAction('generateImage')
        ->assertHasNoActionErrors()
        ->assertFormSet([
            'featured_image' => 'ai-images/generated.png',
        ]);

    ImageGenerationAction::assertCalled();
});

it('includes source field values in the prompt', function () {
    ImageGenerationAction::fake();

    Livewire::test(ImageGenerationFormComponent::class)
        ->fillForm([
            'title' => 'Red Shoes',
            'description' => 'Bright red running shoes',
        ])
        ->callAction('generateWithSource');

    ImageGenerationAction::assertCalledWith(function (string $prompt) {
        expect($prompt)->toContain('Red Shoes')
            ->and($prompt)->toContain('Bright red running shoes');
    });
});

it('resolves size and quality from action level', function () {
    ImageGenerationAction::fake();

    Livewire::test(ImageGenerationFormComponent::class)
        ->fillForm(['title' => 'Test'])
        ->callAction('generateFullOptions');

    ImageGenerationAction::assertCalledWith(function (string $prompt, ?string $size, ?string $quality) {
        expect($size)->toBe('3:2')  // 'landscape' maps to '3:2'
            ->and($quality)->toBe('high');
    });
});

it('resolves provider and model from action level', function () {
    ImageGenerationAction::fake();

    Livewire::test(ImageGenerationFormComponent::class)
        ->fillForm(['title' => 'Test'])
        ->callAction('generateFullOptions');

    ImageGenerationAction::assertCalledWith(function (string $prompt, ?string $size, ?string $quality, $provider, ?string $model) {
        expect($provider)->toBe('openai')
            ->and($model)->toBe('gpt-image-1.5');
    });
});

it('sends success notification after generation', function () {
    ImageGenerationAction::fake();

    Livewire::test(ImageGenerationFormComponent::class)
        ->callAction('generateImage')
        ->assertNotified();
});

it('preserves existing form data for non-target fields', function () {
    ImageGenerationAction::fake('ai-images/new.png');

    Livewire::test(ImageGenerationFormComponent::class)
        ->fillForm([
            'title' => 'My Title',
            'description' => 'My Description',
            'featured_image' => 'old-image.png',
        ])
        ->callAction('generateImage')
        ->assertFormSet([
            'title' => 'My Title',
            'description' => 'My Description',
            'featured_image' => 'ai-images/new.png',
        ]);
});

// ──────────────────────────────────────────────────────────────
//  Source field warnings
// ──────────────────────────────────────────────────────────────

it('warns when source fields are configured but all empty', function () {
    ImageGenerationAction::fake();

    Livewire::test(ImageGenerationFormComponent::class)
        ->callAction('generateWithSource')
        ->assertNotified();

    ImageGenerationAction::assertNotCalled();
});

// ──────────────────────────────────────────────────────────────
//  Validation errors
// ──────────────────────────────────────────────────────────────

it('throws when no target field is configured', function () {
    ImageGenerationAction::fake();

    Livewire::test(ImageGenerationFormComponent::class)
        ->callAction('generateNoTarget');
})->throws(RuntimeException::class, 'requires a target field');

it('throws when no prompt is configured', function () {
    ImageGenerationAction::fake();

    Livewire::test(ImageGenerationFormComponent::class)
        ->callAction('generateNoPrompt');
})->throws(RuntimeException::class, 'requires a prompt');

// ──────────────────────────────────────────────────────────────
//  Assertion methods
// ──────────────────────────────────────────────────────────────

it('tracks call count correctly', function () {
    ImageGenerationAction::fake();

    $component = Livewire::test(ImageGenerationFormComponent::class);

    $component->callAction('generateImage');
    $component->callAction('generateImage');

    ImageGenerationAction::assertCalledTimes(2);
});

// ──────────────────────────────────────────────────────────────
//  Config resolution
// ──────────────────────────────────────────────────────────────

it('resolves provider from config when not set on action', function () {
    config()->set('filament-solaris.image_generation.default_provider', 'gemini');
    config()->set('filament-solaris.image_generation.default_model', 'gemini-image-1');

    ImageGenerationAction::fake();

    Livewire::test(ImageGenerationFormComponent::class)
        ->callAction('generateImage');

    ImageGenerationAction::assertCalledWith(function (string $prompt, ?string $size, ?string $quality, $provider, ?string $model) {
        expect($provider)->toBe('gemini')
            ->and($model)->toBe('gemini-image-1');
    });
});

it('resolves size from config when not set on action', function () {
    config()->set('filament-solaris.image_generation.default_size', '1:1');

    ImageGenerationAction::fake();

    Livewire::test(ImageGenerationFormComponent::class)
        ->callAction('generateImage');

    ImageGenerationAction::assertCalledWith(function (string $prompt, ?string $size) {
        expect($size)->toBe('1:1');
    });
});

it('action-level provider overrides config', function () {
    config()->set('filament-solaris.image_generation.default_provider', 'gemini');

    ImageGenerationAction::fake();

    Livewire::test(ImageGenerationFormComponent::class)
        ->fillForm(['title' => 'Test'])
        ->callAction('generateFullOptions');

    ImageGenerationAction::assertCalledWith(function (string $prompt, ?string $size, ?string $quality, $provider) {
        expect($provider)->toBe('openai');
    });
});
