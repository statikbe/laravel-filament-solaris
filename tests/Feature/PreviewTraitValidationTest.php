<?php

use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Testing\AiFormActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\MissingPreviewTraitComponent;
use Statikbe\FilamentSolaris\Tests\Fixtures\PreviewFormComponent;

beforeEach(function () {
    AiFormActionFake::reset();
});

afterEach(function () {
    AiFormActionFake::reset();
});

it('throws when withPreview() is configured but the Livewire component lacks InteractsWithSolarisPreview', function () {
    AiFormAction::fake(['summary' => 'Generated.']);

    Livewire::test(MissingPreviewTraitComponent::class)
        ->fillForm(['title' => 'Article', 'body' => 'Body.'])
        ->callAction('generateSummary');
})->throws(RuntimeException::class, 'InteractsWithSolarisPreview trait');

it('throws when conversational() is configured but the trait is missing', function () {
    AiFormAction::fake(['summary' => 'Generated.']);

    Livewire::test(MissingPreviewTraitComponent::class)
        ->fillForm(['title' => 'Article', 'body' => 'Body.'])
        ->callAction('refineSummary');
})->throws(RuntimeException::class, 'InteractsWithSolarisPreview trait');

it('passes when the Livewire component uses the InteractsWithSolarisPreview trait', function () {
    AiFormAction::fake(['summary' => 'Generated with preview.']);

    Livewire::test(PreviewFormComponent::class)
        ->fillForm(['title' => 'Article', 'body' => 'Body.'])
        ->callAction('generateSummary');

    AiFormAction::assertCalled();
});
