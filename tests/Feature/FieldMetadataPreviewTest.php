<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Testing\AiFormActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\ConversationalFormComponent;
use Statikbe\FilamentSolaris\Tests\Fixtures\PreviewFormComponent;

beforeEach(function () {
    AiFormActionFake::reset();
    PreviewFormComponent::$written = [];
});

afterEach(function () {
    AiFormActionFake::reset();
});

function metadataEnvelopes(): array
{
    return [
        'summary' => ['value' => 'S', 'evidence' => 'derived'],
        'category' => ['value' => 'tech', 'evidence' => 'verbatim'],
    ];
}

it('stores metadata in the preview data', function () {
    AiFormAction::fake(metadataEnvelopes());

    $component = Livewire::test(PreviewFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('previewWithMetadata');

    expect($component->get('solarisPreviewData.metadata'))->toBe([
        'summary' => ['evidence' => 'derived'],
        'category' => ['evidence' => 'verbatim'],
    ]);
});

it('adds no metadata key when metadata is off', function () {
    AiFormAction::fake(['summary' => 'S', 'category' => 'tech', 'is_featured' => true]);

    $data = Livewire::test(PreviewFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateAll')
        ->get('solarisPreviewData');

    expect($data)->not->toHaveKey('metadata');
});

it('calls afterFieldsWritten on Accept with the ticked fields only', function () {
    AiFormAction::fake(metadataEnvelopes());

    Livewire::test(PreviewFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('previewWithMetadata')
        ->set('solarisPreviewSelected', ['summary'])
        ->call('solarisAcceptPreview')
        ->assertFormSet(['summary' => 'S', 'category' => null]);

    expect(PreviewFormComponent::$written)->toBe([[
        'values' => ['summary' => 'S'],
        'metadata' => ['summary' => ['evidence' => 'derived']],
    ]]);
});

it('does not call afterFieldsWritten when nothing is ticked', function () {
    AiFormAction::fake(metadataEnvelopes());

    Livewire::test(PreviewFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('previewWithMetadata')
        ->set('solarisPreviewSelected', [])
        ->call('solarisAcceptPreview');

    expect(PreviewFormComponent::$written)->toBe([]);
});

it('replaces metadata on refinement and keeps _message top-level', function () {
    AiFormAction::fake(metadataEnvelopes())
        ->fakeRefinement([
            '_message' => 'Updated',
            'summary' => ['value' => 'S2', 'evidence' => 'verbatim'],
            'category' => null,
        ]);

    $component = Livewire::test(ConversationalFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateWithMetadata')
        ->call('solarisRefinePreview', 'Tighten it');

    $messages = $component->get('solarisPreviewData.messages');

    expect($component->get('solarisPreviewData.metadata'))->toBe(['summary' => ['evidence' => 'verbatim']])
        ->and($component->get('solarisPreviewData.values'))->toBe(['summary' => 'S2'])
        ->and(end($messages)['content'])->toBe('Updated');
});

it('renders the info icon only for fields with metadata', function () {
    $displays = [
        'summary' => ['label' => 'Summary', 'display' => 'S', 'type' => 'text'],
        'category' => ['label' => 'Category', 'display' => 'Technology', 'type' => 'text'],
    ];

    $with = Blade::render(
        '<x-filament-solaris::preview-fields :displays="$displays" :metadata="$metadata" />',
        ['displays' => $displays, 'metadata' => ['summary' => ['evidence' => 'derived']]],
    );
    $without = Blade::render('<x-filament-solaris::preview-fields :displays="$displays" />', ['displays' => $displays]);

    expect(substr_count($with, 'x-tooltip'))->toBe(1)
        ->and($with)->toContain('evidence: derived')
        ->and($without)->not->toContain('x-tooltip');
});

it('passes metadata into the mounted preview modal', function () {
    AiFormAction::fake(metadataEnvelopes());

    $component = Livewire::test(PreviewFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('previewWithMetadata');

    expect($component->instance()->getMountedAction()->getModalContent()->getData()['metadata'])
        ->toBe($component->get('solarisPreviewData.metadata'));
});

it('ignores accepted values for fields that are not target fields', function () {
    AiFormAction::fake(metadataEnvelopes());

    $component = Livewire::test(PreviewFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateWithMetadataPreview');

    $data = $component->get('solarisPreviewData');
    $data['values']['title'] = 'Hacked';

    $component->instance()->getMountedAction()->acceptPreview($data);

    $component->assertFormSet(['title' => 'T', 'summary' => 'S']);

    expect(PreviewFormComponent::$written[0]['values'])->toBe(['summary' => 'S', 'category' => 'tech']);
});
