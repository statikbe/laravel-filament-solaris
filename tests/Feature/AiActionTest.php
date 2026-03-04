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

it('fills a single target field with faked AI response', function () {
    AiAction::fake(['summary' => 'A concise summary of the content.']);

    Livewire::test(AiFormComponent::class)
        ->fillForm([
            'title' => 'My Article',
            'body' => 'This is the body of my article about technology.',
        ])
        ->callAction('generateSummary')
        ->assertHasNoActionErrors()
        ->assertFormSet([
            'summary' => 'A concise summary of the content.',
        ]);

    AiAction::assertCalled();
});

it('fills multiple target fields with faked AI response', function () {
    AiAction::fake([
        'summary' => 'Tech article summary.',
        'category' => 'tech',
        'is_featured' => true,
    ]);

    Livewire::test(AiFormComponent::class)
        ->fillForm([
            'title' => 'Breaking Tech News',
            'body' => 'Revolutionary new technology discovered.',
        ])
        ->callAction('generateAll')
        ->assertHasNoActionErrors()
        ->assertFormSet([
            'summary' => 'Tech article summary.',
            'category' => 'tech',
            'is_featured' => true,
        ]);

    AiAction::assertCalled();
});

it('reads source field values from form state', function () {
    AiAction::fake(['summary' => 'Test summary']);

    Livewire::test(AiFormComponent::class)
        ->fillForm([
            'title' => 'Source Title',
            'body' => 'Source Body Content',
        ])
        ->callAction('generateSummary');

    AiAction::assertCalledWith(function (array $sourceData, string $prompt) {
        expect($sourceData['title'])->toBe('Source Title')
            ->and($sourceData['body'])->toBe('Source Body Content');
    });
});

it('includes prompt instruction in the composed prompt', function () {
    AiAction::fake(['summary' => 'Test']);

    Livewire::test(AiFormComponent::class)
        ->fillForm([
            'title' => 'Test',
            'body' => 'Content',
        ])
        ->callAction('generateSummary');

    AiAction::assertCalledWith(function (array $sourceData, string $prompt) {
        expect($prompt)->toContain('Summarize the content in one sentence.');
    });
});

it('fuzzy matches select values from AI response', function () {
    AiAction::fake([
        'summary' => 'Summary',
        'category' => 'Technology',  // AI returns label, not key
        'is_featured' => false,
    ]);

    Livewire::test(AiFormComponent::class)
        ->fillForm([
            'title' => 'Test',
            'body' => 'Content',
        ])
        ->callAction('generateAll')
        ->assertFormSet([
            'category' => 'tech',  // Factory resolves label → key
        ]);
});

it('converts boolean strings from AI response', function () {
    AiAction::fake([
        'summary' => 'Summary',
        'category' => 'tech',
        'is_featured' => 'yes',  // AI returns string, factory converts to bool
    ]);

    Livewire::test(AiFormComponent::class)
        ->fillForm([
            'title' => 'Test',
            'body' => 'Content',
        ])
        ->callAction('generateAll')
        ->assertFormSet([
            'is_featured' => true,
        ]);
});

it('shows error notification when AI returns empty response', function () {
    AiAction::fake([]);

    Livewire::test(AiFormComponent::class)
        ->fillForm([
            'title' => 'Test',
            'body' => 'Content',
        ])
        ->callAction('generateSummary')
        ->assertNotified();
});

it('shows error notification on simulated AI error', function () {
    AiAction::fakeError('Service unavailable');

    Livewire::test(AiFormComponent::class)
        ->fillForm([
            'title' => 'Test',
            'body' => 'Content',
        ])
        ->callAction('generateSummary')
        ->assertNotified();

    AiAction::assertCalled();
});

it('shows error notification on simulated timeout', function () {
    AiAction::fakeTimeout();

    Livewire::test(AiFormComponent::class)
        ->fillForm([
            'title' => 'Test',
            'body' => 'Content',
        ])
        ->callAction('generateSummary')
        ->assertNotified();

    AiAction::assertCalled();
});

it('handles partial response with some null fields', function () {
    AiAction::fakePartial([
        'summary' => 'Partial summary',
        'category' => null,  // AI couldn't determine category
        'is_featured' => true,
    ]);

    Livewire::test(AiFormComponent::class)
        ->fillForm([
            'title' => 'Test',
            'body' => 'Content',
        ])
        ->callAction('generateAll')
        ->assertFormSet([
            'summary' => 'Partial summary',
            'is_featured' => true,
        ])
        ->assertNotified();
});

it('tracks the number of AI calls', function () {
    AiAction::fake(['summary' => 'First call']);

    $component = Livewire::test(AiFormComponent::class)
        ->fillForm([
            'title' => 'Test',
            'body' => 'Content',
        ]);

    $component->callAction('generateSummary');
    $component->callAction('generateSummary');

    AiAction::assertCalledTimes(2);
});

it('preserves existing form data for non-target fields', function () {
    AiAction::fake(['summary' => 'Generated summary']);

    Livewire::test(AiFormComponent::class)
        ->fillForm([
            'title' => 'My Title',
            'body' => 'My Body',
            'category' => 'science',
            'is_featured' => true,
        ])
        ->callAction('generateSummary')
        ->assertFormSet([
            'title' => 'My Title',
            'body' => 'My Body',
            'summary' => 'Generated summary',
            'category' => 'science',
            'is_featured' => true,
        ]);
});
