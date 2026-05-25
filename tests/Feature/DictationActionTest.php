<?php

use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Actions\DictationFieldAction;
use Statikbe\FilamentSolaris\Testing\AiFormActionFake;
use Statikbe\FilamentSolaris\Testing\DictationFieldActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\DictationFormComponent;

beforeEach(function () {
    DictationFieldActionFake::reset();
    AiFormActionFake::reset();
});

afterEach(function () {
    DictationFieldActionFake::reset();
    AiFormActionFake::reset();
});

// ──────────────────────────────────────────────────────────────
//  Pure transcription mode (suffix attached to a Textarea)
// ──────────────────────────────────────────────────────────────

it('writes transcript to the host field when configured as a suffix action', function () {
    DictationFieldAction::fake('Hello, this is a test transcription.');

    Livewire::test(DictationFormComponent::class)
        ->callFormComponentAction('body', 'dictateBody')
        ->assertHasNoFormComponentActionErrors()
        ->assertFormSet([
            'body' => 'Hello, this is a test transcription.',
        ]);

    DictationFieldAction::assertCalled();
    DictationFieldAction::assertTranscribed();
});

it('appends transcript to existing host field content in append mode', function () {
    DictationFieldAction::fake('Second paragraph.');

    Livewire::test(DictationFormComponent::class)
        ->fillForm([
            'body' => 'First paragraph.',
        ])
        ->callFormComponentAction('body', 'dictateBodyAppend')
        ->assertFormSet([
            'body' => "First paragraph.\nSecond paragraph.",
        ]);
});

it('replaces content when append mode is disabled', function () {
    DictationFieldAction::fake('New content.');

    Livewire::test(DictationFormComponent::class)
        ->fillForm([
            'body' => 'Old content.',
        ])
        ->callFormComponentAction('body', 'dictateBody')
        ->assertFormSet([
            'body' => 'New content.',
        ]);
});

it('appends to empty field without leading newline', function () {
    DictationFieldAction::fake('First dictation.');

    Livewire::test(DictationFormComponent::class)
        ->callFormComponentAction('body', 'dictateBodyAppend')
        ->assertFormSet([
            'body' => 'First dictation.',
        ]);
});

it('shows success notification after transcription', function () {
    DictationFieldAction::fake('Transcribed text.');

    Livewire::test(DictationFormComponent::class)
        ->callFormComponentAction('body', 'dictateBody')
        ->assertNotified();
});

// ──────────────────────────────────────────────────────────────
//  AI chaining mode (transcription + prompt pipeline)
// ──────────────────────────────────────────────────────────────

it('chains transcript through AI pipeline when prompt is configured', function () {
    DictationFieldAction::fake(
        transcript: 'This is a long article about quantum computing and its impact on cryptography.',
        aiResponse: ['summary' => 'Quantum computing threatens current cryptographic methods.'],
    );

    Livewire::test(DictationFormComponent::class)
        ->callFormComponentAction('summary', 'dictateAndSummarize')
        ->assertFormSet([
            'summary' => 'Quantum computing threatens current cryptographic methods.',
        ]);

    DictationFieldAction::assertCalled();
    DictationFieldAction::assertTranscribed();
});

it('passes transcript as source data to AI pipeline', function () {
    DictationFieldAction::fake(
        transcript: 'My transcribed audio content.',
        aiResponse: ['summary' => 'A summary.'],
    );

    Livewire::test(DictationFormComponent::class)
        ->callFormComponentAction('summary', 'dictateAndSummarize');

    AiFormAction::assertCalledWith(function (array $sourceData, string $prompt) {
        expect($sourceData)->toHaveKey('transcription')
            ->and($sourceData['transcription'])->toBe('My transcribed audio content.');
    });
});

it('fills multiple target fields through AI pipeline', function () {
    DictationFieldAction::fake(
        transcript: 'Breaking news about a new scientific discovery.',
        aiResponse: [
            'summary' => 'New scientific discovery announced.',
            'category' => 'science',
        ],
    );

    Livewire::test(DictationFormComponent::class)
        ->callFormComponentAction('summary', 'dictateAndClassify')
        ->assertFormSet([
            'summary' => 'New scientific discovery announced.',
            'category' => 'science',
        ]);
});

it('shows error notification when AI pipeline returns empty response', function () {
    DictationFieldAction::fake(
        transcript: 'Some audio content.',
        aiResponse: [],
    );

    Livewire::test(DictationFormComponent::class)
        ->callFormComponentAction('summary', 'dictateAndSummarize')
        ->assertNotified();
});

// ──────────────────────────────────────────────────────────────
//  Assertion methods
// ──────────────────────────────────────────────────────────────

it('asserts transcript content with callback', function () {
    DictationFieldAction::fake('Expected transcript.');

    Livewire::test(DictationFormComponent::class)
        ->callFormComponentAction('body', 'dictateBody');

    DictationFieldAction::assertTranscribedWith(function (string $transcript) {
        expect($transcript)->toBe('Expected transcript.');
    });
});

it('tracks call count correctly', function () {
    DictationFieldAction::fake('Dictation text.');

    $component = Livewire::test(DictationFormComponent::class);

    $component->callFormComponentAction('body', 'dictateBody');
    $component->callFormComponentAction('body', 'dictateBody');

    DictationFieldAction::assertCalledTimes(2);
});

it('preserves existing form data for non-target fields', function () {
    DictationFieldAction::fake('Transcribed text.');

    Livewire::test(DictationFormComponent::class)
        ->fillForm([
            'title' => 'My Title',
            'body' => 'Old body',
            'summary' => 'Existing summary',
            'category' => 'tech',
        ])
        ->callFormComponentAction('body', 'dictateBody')
        ->assertFormSet([
            'title' => 'My Title',
            'body' => 'Transcribed text.',
            'summary' => 'Existing summary',
            'category' => 'tech',
        ]);
});
