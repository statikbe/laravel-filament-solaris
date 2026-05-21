<?php

use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiAction;
use Statikbe\FilamentSolaris\Testing\AiActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\MissingPreviewTraitComponent;
use Statikbe\FilamentSolaris\Tests\Fixtures\PreviewFormComponent;

beforeEach(function () {
    AiActionFake::reset();
});

afterEach(function () {
    AiActionFake::reset();
});

it('throws when withPreview() is configured but the Livewire component lacks InteractsWithSolarisPreview', function () {
    AiAction::fake(['summary' => 'Generated.']);

    Livewire::test(MissingPreviewTraitComponent::class)
        ->fillForm(['title' => 'Article', 'body' => 'Body.'])
        ->callAction('generateSummary');
})->throws(RuntimeException::class, 'InteractsWithSolarisPreview trait');

it('throws when conversational() is configured but the trait is missing', function () {
    AiAction::fake(['summary' => 'Generated.']);

    Livewire::test(MissingPreviewTraitComponent::class)
        ->fillForm(['title' => 'Article', 'body' => 'Body.'])
        ->callAction('refineSummary');
})->throws(RuntimeException::class, 'InteractsWithSolarisPreview trait');

it('passes when the Livewire component uses the InteractsWithSolarisPreview trait', function () {
    AiAction::fake(['summary' => 'Generated with preview.']);

    Livewire::test(PreviewFormComponent::class)
        ->fillForm(['title' => 'Article', 'body' => 'Body.'])
        ->callAction('generateSummary');

    AiAction::assertCalled();
});
