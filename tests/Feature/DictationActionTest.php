<?php

use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiAction;
use Statikbe\FilamentSolaris\Actions\DictationAction;
use Statikbe\FilamentSolaris\Testing\AiActionFake;
use Statikbe\FilamentSolaris\Testing\DictationActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\DictationFormComponent;

beforeEach(function () {
    DictationActionFake::reset();
    AiActionFake::reset();
});

afterEach(function () {
    DictationActionFake::reset();
    AiActionFake::reset();
});

// ──────────────────────────────────────────────────────────────
//  Pure transcription mode
// ──────────────────────────────────────────────────────────────

it('writes transcript to target field in pure transcription mode', function () {
    DictationAction::fake('Hello, this is a test transcription.');

    Livewire::test(DictationFormComponent::class)
        ->callAction('dictateBody')
        ->assertHasNoActionErrors()
        ->assertFormSet([
            'body' => 'Hello, this is a test transcription.',
        ]);

    DictationAction::assertCalled();
    DictationAction::assertTranscribed();
});

it('appends transcript to existing field content in append mode', function () {
    DictationAction::fake('Second paragraph.');

    Livewire::test(DictationFormComponent::class)
        ->fillForm([
            'body' => 'First paragraph.',
        ])
        ->callAction('dictateBodyAppend')
        ->assertFormSet([
            'body' => "First paragraph.\nSecond paragraph.",
        ]);
});

it('replaces content when append mode is disabled', function () {
    DictationAction::fake('New content.');

    Livewire::test(DictationFormComponent::class)
        ->fillForm([
            'body' => 'Old content.',
        ])
        ->callAction('dictateBody')
        ->assertFormSet([
            'body' => 'New content.',
        ]);
});

it('appends to empty field without leading newline', function () {
    DictationAction::fake('First dictation.');

    Livewire::test(DictationFormComponent::class)
        ->callAction('dictateBodyAppend')
        ->assertFormSet([
            'body' => 'First dictation.',
        ]);
});

it('shows success notification after transcription', function () {
    DictationAction::fake('Transcribed text.');

    Livewire::test(DictationFormComponent::class)
        ->callAction('dictateBody')
        ->assertNotified();
});

// ──────────────────────────────────────────────────────────────
//  AI chaining mode (transcription + prompt pipeline)
// ──────────────────────────────────────────────────────────────

it('chains transcript through AI pipeline when prompt is configured', function () {
    DictationAction::fake(
        transcript: 'This is a long article about quantum computing and its impact on cryptography.',
        aiResponse: ['summary' => 'Quantum computing threatens current cryptographic methods.'],
    );

    Livewire::test(DictationFormComponent::class)
        ->callAction('dictateAndSummarize')
        ->assertHasNoActionErrors()
        ->assertFormSet([
            'summary' => 'Quantum computing threatens current cryptographic methods.',
        ]);

    DictationAction::assertCalled();
    DictationAction::assertTranscribed();
});

it('passes transcript as source data to AI pipeline', function () {
    DictationAction::fake(
        transcript: 'My transcribed audio content.',
        aiResponse: ['summary' => 'A summary.'],
    );

    Livewire::test(DictationFormComponent::class)
        ->callAction('dictateAndSummarize');

    AiAction::assertCalledWith(function (array $sourceData, string $prompt) {
        expect($sourceData)->toHaveKey('transcription')
            ->and($sourceData['transcription'])->toBe('My transcribed audio content.');
    });
});

it('fills multiple target fields through AI pipeline', function () {
    DictationAction::fake(
        transcript: 'Breaking news about a new scientific discovery.',
        aiResponse: [
            'summary' => 'New scientific discovery announced.',
            'category' => 'science',
        ],
    );

    Livewire::test(DictationFormComponent::class)
        ->callAction('dictateAndClassify')
        ->assertFormSet([
            'summary' => 'New scientific discovery announced.',
            'category' => 'science',
        ]);
});

it('shows error notification when AI pipeline returns empty response', function () {
    DictationAction::fake(
        transcript: 'Some audio content.',
        aiResponse: [],
    );

    Livewire::test(DictationFormComponent::class)
        ->callAction('dictateAndSummarize')
        ->assertNotified();
});

// ──────────────────────────────────────────────────────────────
//  Error handling
// ──────────────────────────────────────────────────────────────

it('throws when no target fields are configured', function () {
    DictationAction::fake('Test');

    Livewire::test(DictationFormComponent::class)
        ->callAction('dictateInvalid');
})->throws(RuntimeException::class, 'requires at least one target field');

// ──────────────────────────────────────────────────────────────
//  Assertion methods
// ──────────────────────────────────────────────────────────────

it('asserts transcript content with callback', function () {
    DictationAction::fake('Expected transcript.');

    Livewire::test(DictationFormComponent::class)
        ->callAction('dictateBody');

    DictationAction::assertTranscribedWith(function (string $transcript) {
        expect($transcript)->toBe('Expected transcript.');
    });
});

it('tracks call count correctly', function () {
    DictationAction::fake('Dictation text.');

    $component = Livewire::test(DictationFormComponent::class);

    $component->callAction('dictateBody');
    $component->callAction('dictateBody');

    DictationAction::assertCalledTimes(2);
});

it('preserves existing form data for non-target fields', function () {
    DictationAction::fake('Transcribed text.');

    Livewire::test(DictationFormComponent::class)
        ->fillForm([
            'title' => 'My Title',
            'body' => 'Old body',
            'summary' => 'Existing summary',
            'category' => 'tech',
        ])
        ->callAction('dictateBody')
        ->assertFormSet([
            'title' => 'My Title',
            'body' => 'Transcribed text.',
            'summary' => 'Existing summary',
            'category' => 'tech',
        ]);
});
