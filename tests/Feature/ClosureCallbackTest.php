<?php

use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiAction;
use Statikbe\FilamentSolaris\Testing\AiActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\ClosureCallbackFormComponent;

beforeEach(fn () => AiActionFake::reset());
afterEach(fn () => AiActionFake::reset());

// ──────────────────────────────────────────────────────────────
//  prompt() closure
// ──────────────────────────────────────────────────────────────

it('injects $record and $sourceData into a prompt closure', function () {
    AiAction::fake(['summary' => 'A summary.']);

    Livewire::test(ClosureCallbackFormComponent::class)
        ->fillForm(['title' => 'Filament Solaris', 'body' => 'Body text.'])
        ->callAction('promptClosure');

    AiAction::assertCalledWith(function (array $sourceData, string $prompt) {
        expect($prompt)
            ->toContain('developers')          // from $record->audience
            ->toContain('Filament Solaris');   // from $sourceData['title']
    });
});

it('selects the view builder when a prompt closure returns a View', function () {
    AiAction::fake(['summary' => 'A summary.']);

    Livewire::test(ClosureCallbackFormComponent::class)
        ->fillForm(['title' => 'Filament Solaris', 'body' => 'Body text.'])
        ->callAction('promptViewClosure')
        ->assertHasNoActionErrors();

    AiAction::assertCalledWith(function (array $sourceData, string $prompt) {
        expect($prompt)->toContain('View prompt for a developers audience');
    });
});

// ──────────────────────────────────────────────────────────────
//  sourceFields() closure
// ──────────────────────────────────────────────────────────────

it('injects $record into a sourceFields closure', function () {
    AiAction::fake(['summary' => 'A summary.']);

    Livewire::test(ClosureCallbackFormComponent::class)
        ->fillForm(['title' => 'Filament Solaris', 'body' => 'Body text.'])
        ->callAction('sourceFieldsClosure');

    // The closure returns $record->summary_fields (['title', 'body']); if $record
    // were not injected, calling the closure would error.
    AiAction::assertCalledWith(function (array $sourceData) {
        expect($sourceData)->toHaveKeys(['title', 'body'])
            ->and($sourceData['title'])->toBe('Filament Solaris');
    });
});

// ──────────────────────────────────────────────────────────────
//  targetFields() closure
// ──────────────────────────────────────────────────────────────

it('injects $record into a targetFields closure and fills the resolved fields', function () {
    AiAction::fake([
        'summary' => 'Generated summary.',
        'category' => 'tech',
    ]);

    Livewire::test(ClosureCallbackFormComponent::class)
        ->fillForm(['title' => 'Filament Solaris', 'body' => 'Body text.'])
        ->callAction('targetFieldsClosure')
        ->assertFormSet([
            'summary' => 'Generated summary.',
            'category' => 'tech',
        ]);
});
