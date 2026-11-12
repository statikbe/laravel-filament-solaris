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

function seededPreview(string $action, string $component = PreviewFormComponent::class)
{
    return Livewire::test($component)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction($action);
}

it('ticks only verbatim fields on the first preview', function () {
    AiFormAction::fake([
        'summary' => ['value' => 'S', 'evidence' => 'verbatim'],
        'category' => ['value' => 'tech', 'evidence' => 'derived'],
        'is_featured' => ['value' => true, 'evidence' => 'derived'],
    ]);

    expect(seededPreview('previewEvidence')->get('solarisPreviewSelected'))->toBe(['summary']);
});

it('ticks every field for closure-defined metadata', function () {
    AiFormAction::fake([
        'summary' => ['value' => 'S', 'evidence' => 'derived'],
        'category' => ['value' => 'tech', 'evidence' => 'derived'],
    ]);

    expect(seededPreview('previewWithMetadata')->get('solarisPreviewSelected'))->toBe(['summary', 'category']);
});

it('disables Accept when everything starts unticked, and writes a ticked field', function () {
    AiFormAction::fake([
        'summary' => ['value' => 'S', 'evidence' => 'derived'],
        'category' => ['value' => 'tech', 'evidence' => 'derived'],
        'is_featured' => ['value' => true, 'evidence' => 'derived'],
    ]);

    $component = seededPreview('previewEvidence');

    expect($component->get('solarisPreviewSelected'))->toBe([])
        ->and(view('filament-solaris::preview-modal-footer', ['requiresSelection' => true])->render())
        ->toContain('x-bind:disabled="! ($wire.solarisPreviewSelected ?? []).length"');

    $component
        ->set('solarisPreviewSelected', ['category'])
        ->call('solarisAcceptPreview')
        ->assertFormSet(['summary' => '', 'category' => 'tech', 'is_featured' => false]);
});

it('re-seeds changed values on refinement and keeps unchanged ticks', function () {
    AiFormAction::fake([
        'summary' => ['value' => 'S1', 'evidence' => 'derived'],
        'category' => ['value' => 'tech', 'evidence' => 'verbatim'],
    ])->fakeRefinement([
        'summary' => ['value' => 'S2', 'evidence' => 'verbatim'],
        'category' => ['value' => 'tech', 'evidence' => 'verbatim'],
    ]);

    $component = seededPreview('generateEvidenceSelectable', ConversationalFormComponent::class);

    expect($component->get('solarisPreviewSelected'))->toBe(['category']);

    $component
        ->set('solarisPreviewSelected', [])
        ->call('solarisRefinePreview', 'Quote it');

    expect($component->get('solarisPreviewSelected'))->toBe(['summary']);
});

it('ignores metadata seeding when selection is off', function () {
    AiFormAction::fake([
        'summary' => ['value' => 'S', 'evidence' => 'derived'],
        'category' => ['value' => 'tech', 'evidence' => 'derived'],
    ]);

    expect(seededPreview('generateWithMetadataPreview')->get('solarisPreviewSelected'))->toBeNull();
});
