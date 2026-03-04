<?php

use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiAction;
use Statikbe\FilamentSolaris\Testing\AiActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\AiFormComponent;

beforeEach(function () {
    AiActionFake::reset();
});

afterEach(function () {
    AiActionFake::reset();
});

it('includes locale override in the prompt', function () {
    AiAction::fake(['summary' => 'Dutch summary']);

    Livewire::test(AiFormComponent::class)
        ->fillForm([
            'title' => 'Test',
            'body' => 'Content',
        ])
        ->callAction('generateWithLocale');

    AiAction::assertCalledWith(function (array $sourceData, string $prompt) {
        expect($prompt)->toContain('Dutch');
    });
});

it('fills target fields with locale override active', function () {
    AiAction::fake(['summary' => 'Nederlandse samenvatting']);

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
