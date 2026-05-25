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

it('applies source scope to transform source field values', function () {
    AiFormAction::fake(['summary' => 'Scoped summary']);

    Livewire::test(AiFormComponent::class)
        ->fillForm([
            'title' => 'Test Title',
            'body' => 'lower case body',
        ])
        ->callAction('generateWithSourceScope');

    AiFormAction::assertCalledWith(function (array $sourceData, string $prompt) {
        expect($sourceData['body'])->toBe('LOWER CASE BODY')
            ->and($sourceData['title'])->toBe('Test Title');
    });
});

it('applies source scope and still fills target fields', function () {
    AiFormAction::fake(['summary' => 'Result from scoped data']);

    Livewire::test(AiFormComponent::class)
        ->fillForm([
            'title' => 'Test',
            'body' => 'scoped content',
        ])
        ->callAction('generateWithSourceScope')
        ->assertFormSet([
            'summary' => 'Result from scoped data',
        ]);
});
