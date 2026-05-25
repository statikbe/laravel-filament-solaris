<?php

use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Testing\AiFormActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\AiFormComponent;

beforeEach(function () {
    AiFormActionFake::reset();
});

afterEach(function () {
    AiFormActionFake::reset();
});

it('includes locale override in the prompt', function () {
    AiFormAction::fake(['summary' => 'Dutch summary']);

    Livewire::test(AiFormComponent::class)
        ->fillForm([
            'title' => 'Test',
            'body' => 'Content',
        ])
        ->callAction('generateWithLocale');

    AiFormAction::assertCalledWith(function (array $sourceData, string $prompt) {
        expect($prompt)->toContain('Dutch');
    });
});

it('fills target fields with locale override active', function () {
    AiFormAction::fake(['summary' => 'Nederlandse samenvatting']);

    Livewire::test(AiFormComponent::class)
        ->fillForm([
            'title' => 'Test',
            'body' => 'Content',
        ])
        ->callAction('generateWithLocale')
        ->assertFormSet([
            'summary' => 'Nederlandse samenvatting',
        ]);
});
