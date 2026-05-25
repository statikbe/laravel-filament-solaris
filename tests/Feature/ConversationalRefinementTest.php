<?php

use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Testing\AiFormActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\ConversationalFormComponent;
use Statikbe\FilamentSolaris\Tests\Fixtures\PreviewFormComponent;

beforeEach(function () {
    AiFormActionFake::reset();
});

afterEach(function () {
    AiFormActionFake::reset();
});

it('conversational implies withPreview', function () {
    $action = AiFormAction::make('test')
        ->targetField('summary')
        ->prompt('Summarize.')
        ->conversational();

    expect($action->isConversational())->toBeTrue()
        ->and($action->shouldPreview())->toBeTrue();
});

it('stores preview data with conversation context', function () {
    AiFormAction::fake(['summary' => 'Initial summary.']);

    $component = Livewire::test(ConversationalFormComponent::class)
        ->fillForm([
            'title' => 'Test Title',
            'body' => 'Test Body',
        ])
        ->callAction('generateSummary');

    $previewData = $component->get('solarisPreviewData');

    expect($previewData)->not->toBeNull()
        ->and($previewData['isConversational'])->toBeTrue()
        ->and($previewData['conversationId'])->toBe('fake-conversation-id')
        ->and($previewData['messages'])->toBeArray()
        ->and($previewData['messages'])->toHaveCount(1)
        ->and($previewData['messages'][0]['role'])->toBe('assistant')
        ->and($previewData['sourceData'])->toHaveKey('title')
        ->and($previewData['values'])->toHaveKey('summary');
});

it('does not apply values to form on initial call', function () {
    AiFormAction::fake(['summary' => 'Initial summary.']);

    Livewire::test(ConversationalFormComponent::class)
        ->fillForm([
            'title' => 'Test',
            'body' => 'Content',
        ])
        ->callAction('generateSummary')
        ->assertFormSet([
            'summary' => '',
        ]);
});

it('refines preview values with a follow-up message', function () {
    AiFormAction::fake(['summary' => 'Initial summary.'])
        ->fakeRefinement(['summary' => 'Refined and shorter summary.']);

    $component = Livewire::test(ConversationalFormComponent::class)
        ->fillForm([
            'title' => 'Test',
            'body' => 'Content',
        ])
        ->callAction('generateSummary');

    // Verify initial preview
    expect($component->get('solarisPreviewData.values.summary'))->toBe('Initial summary.');

    // Send refinement
    $component->call('solarisRefinePreview', 'Make it shorter');

    // Verify updated preview
    $previewData = $component->get('solarisPreviewData');
    expect($previewData['values']['summary'])->toBe('Refined and shorter summary.');
});

it('appends messages during refinement', function () {
    AiFormAction::fake(['summary' => 'Initial.'])
        ->fakeRefinement(['summary' => 'Refined.']);

    $component = Livewire::test(ConversationalFormComponent::class)
        ->fillForm([
            'title' => 'Test',
            'body' => 'Content',
        ])
        ->callAction('generateSummary')
        ->call('solarisRefinePreview', 'Make it better');

    $messages = $component->get('solarisPreviewData.messages');

    // Initial assistant + user refinement + assistant response = 3
    expect($messages)->toHaveCount(3)
        ->and($messages[0]['role'])->toBe('assistant')
        ->and($messages[1]['role'])->toBe('user')
        ->and($messages[1]['content'])->toBe('Make it better')
        ->and($messages[2]['role'])->toBe('assistant');
});

it('accepts refined values into the form', function () {
    AiFormAction::fake(['summary' => 'Initial.'])
        ->fakeRefinement(['summary' => 'Final refined version.']);

    Livewire::test(ConversationalFormComponent::class)
        ->fillForm([
            'title' => 'Test',
            'body' => 'Content',
        ])
        ->callAction('generateSummary')
        ->call('solarisRefinePreview', 'Improve it')
        ->call('solarisAcceptPreview')
        ->assertFormSet([
            'summary' => 'Final refined version.',
        ])
        ->assertSet('solarisPreviewData', null);
});

it('discards refined values without applying', function () {
    AiFormAction::fake(['summary' => 'Initial.'])
        ->fakeRefinement(['summary' => 'Refined.']);

    Livewire::test(ConversationalFormComponent::class)
        ->fillForm([
            'title' => 'Test',
            'body' => 'Content',
        ])
        ->callAction('generateSummary')
        ->call('solarisRefinePreview', 'Improve it')
        ->call('solarisDiscardPreview')
        ->assertFormSet([
            'summary' => '',
        ])
        ->assertSet('solarisPreviewData', null);
});

it('supports multiple refinement rounds', function () {
    AiFormAction::fake(['summary' => 'First.'])
        ->fakeRefinement(['summary' => 'Second.'])
        ->fakeRefinement(['summary' => 'Third.']);

    $component = Livewire::test(ConversationalFormComponent::class)
        ->fillForm([
            'title' => 'Test',
            'body' => 'Content',
        ])
        ->callAction('generateSummary')
        ->call('solarisRefinePreview', 'Refinement 1')
        ->call('solarisRefinePreview', 'Refinement 2');

    expect($component->get('solarisPreviewData.values.summary'))->toBe('Third.')
        ->and($component->get('solarisPreviewData.messages'))->toHaveCount(5);

    AiFormActionFake::getInstance()->assertRefinedTimes(2);
});

it('records refinement calls in fake', function () {
    AiFormAction::fake(['summary' => 'Initial.'])
        ->fakeRefinement(['summary' => 'Refined.']);

    Livewire::test(ConversationalFormComponent::class)
        ->fillForm([
            'title' => 'Test',
            'body' => 'Content',
        ])
        ->callAction('generateSummary')
        ->call('solarisRefinePreview', 'Make it formal');

    AiFormActionFake::getInstance()->assertRefined();
    AiFormActionFake::getInstance()->assertRefinedWith(function (string $message) {
        expect($message)->toBe('Make it formal');
    });
});

it('ignores refinement when preview is not conversational', function () {
    // Use a non-conversational fixture — the regular preview
    AiFormAction::fake(['summary' => 'Preview.']);

    $component = Livewire::test(PreviewFormComponent::class)
        ->fillForm([
            'title' => 'Test',
            'body' => 'Content',
        ])
        ->callAction('generateSummary');

    // Attempt refinement — should be a no-op
    $component->call('solarisRefinePreview', 'Should be ignored');

    // Preview data unchanged
    expect($component->get('solarisPreviewData.values.summary'))->toBe('Preview.');
});

it('ignores refinement when no preview data exists', function () {
    $component = Livewire::test(ConversationalFormComponent::class);

    // Should not throw
    $component->call('solarisRefinePreview', 'No preview');

    expect($component->get('solarisPreviewData'))->toBeNull();
});

it('works with multiple target fields in conversational mode', function () {
    AiFormAction::fake(['summary' => 'Initial summary.', 'category' => 'tech'])
        ->fakeRefinement(['summary' => 'Better summary.', 'category' => 'science']);

    $component = Livewire::test(ConversationalFormComponent::class)
        ->fillForm([
            'title' => 'Science Article',
            'body' => 'Content about science.',
        ])
        ->callAction('generateAll')
        ->call('solarisRefinePreview', 'Change category to science');

    expect($component->get('solarisPreviewData.values.summary'))->toBe('Better summary.')
        ->and($component->get('solarisPreviewData.values.category'))->toBe('science');

    // Accept and verify
    $component->call('solarisAcceptPreview')
        ->assertFormSet([
            'summary' => 'Better summary.',
            'category' => 'science',
        ]);
});

it('uses _message from AI response as assistant chat message', function () {
    AiFormAction::fake([
        '_message' => 'Here is a concise summary of your article.',
        'summary' => 'Initial summary.',
    ])->fakeRefinement([
        '_message' => 'I made it shorter and more formal.',
        'summary' => 'Refined summary.',
    ]);

    $component = Livewire::test(ConversationalFormComponent::class)
        ->fillForm([
            'title' => 'Test',
            'body' => 'Content',
        ])
        ->callAction('generateSummary');

    // Initial message from AI
    $messages = $component->get('solarisPreviewData.messages');
    expect($messages[0]['content'])->toBe('Here is a concise summary of your article.');

    // Refinement message from AI
    $component->call('solarisRefinePreview', 'Make it shorter');
    $messages = $component->get('solarisPreviewData.messages');
    expect($messages[2]['content'])->toBe('I made it shorter and more formal.');
});

it('falls back to translation when _message is absent', function () {
    AiFormAction::fake(['summary' => 'Initial.'])
        ->fakeRefinement(['summary' => 'Refined.']);

    $component = Livewire::test(ConversationalFormComponent::class)
        ->fillForm([
            'title' => 'Test',
            'body' => 'Content',
        ])
        ->callAction('generateSummary');

    // Falls back to translation
    $messages = $component->get('solarisPreviewData.messages');
    expect($messages[0]['content'])->toBe(filament_solaris_trans('conversation.initial_message'));

    $component->call('solarisRefinePreview', 'Improve');
    $messages = $component->get('solarisPreviewData.messages');
    expect($messages[2]['content'])->toBe(filament_solaris_trans('conversation.refined_message'));
});
