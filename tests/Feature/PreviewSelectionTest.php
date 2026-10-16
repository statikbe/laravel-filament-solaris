<?php

use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Support\SolarisNotification;
use Statikbe\FilamentSolaris\Testing\AiFormActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\ConversationalFormComponent;
use Statikbe\FilamentSolaris\Tests\Fixtures\PreviewFormComponent;

beforeEach(function () {
    AiFormActionFake::reset();
});

afterEach(function () {
    AiFormActionFake::reset();
});

function selectablePreview(string $action): Testable
{
    return Livewire::test(PreviewFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction($action);
}

it('adds no selection keys by default', function () {
    AiFormAction::fake(['summary' => 'S', 'category' => 'tech', 'is_featured' => true]);

    $data = selectablePreview('generateAll')->get('solarisPreviewData');

    expect($data)->not->toHaveKey('selectable')
        ->and($data)->not->toHaveKey('selected');
});

it('starts with every selectable field ticked', function () {
    AiFormAction::fake(['summary' => 'S', 'category' => 'tech', 'is_featured' => true]);

    $component = selectablePreview('generateSelectable');

    expect($component->get('solarisPreviewData.selectable'))->toBe(['summary', 'category', 'is_featured'])
        ->and($component->get('solarisPreviewData.selected'))->toBe(['summary', 'category', 'is_featured']);
});

it('writes only the ticked fields and reports them as filled', function () {
    AiFormAction::fake(['summary' => 'S', 'category' => 'tech', 'is_featured' => true]);

    selectablePreview('generateSelectable')
        ->set('solarisPreviewData.selected', ['summary'])
        ->call('solarisAcceptPreview')
        ->assertFormSet(['summary' => 'S', 'category' => null, 'is_featured' => false])
        ->assertNotified(filament_solaris_trans('notifications.success', [
            'fields' => SolarisNotification::formatFieldList(['Summary']),
        ]))
        ->assertSet('solarisPreviewData', null);
});

it('writes nothing and sends no notification when nothing is ticked', function () {
    AiFormAction::fake(['summary' => 'S', 'category' => 'tech', 'is_featured' => true]);

    selectablePreview('generateSelectable')
        ->set('solarisPreviewData.selected', [])
        ->call('solarisAcceptPreview')
        ->assertFormSet(['summary' => '', 'category' => null, 'is_featured' => false])
        ->assertNotNotified()
        ->assertSet('solarisPreviewData', null);
});

it('always writes fields outside the selectable subset', function () {
    AiFormAction::fake(['summary' => 'S', 'category' => 'tech', 'is_featured' => true]);

    $component = selectablePreview('generateSubset');

    expect($component->get('solarisPreviewData.selectable'))->toBe(['summary', 'category']);

    $component
        ->set('solarisPreviewData.selected', [])
        ->call('solarisAcceptPreview')
        ->assertFormSet(['summary' => '', 'category' => null, 'is_featured' => true])
        ->assertNotified();
});

it('leaves a listed field the AI failed to fill out of the selectable keys', function () {
    AiFormAction::fakePartial(['summary' => 'S', 'category' => null, 'is_featured' => true]);

    expect(selectablePreview('generateSubset')->get('solarisPreviewData.selectable'))->toBe(['summary']);
});

it('keeps an unticked field unticked after a refinement', function () {
    AiFormAction::fake(['summary' => 'S1', 'category' => 'tech'])
        ->fakeRefinement(['summary' => 'S2', 'category' => 'science']);

    $component = Livewire::test(ConversationalFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateSelectable');

    expect($component->get('solarisPreviewData.selected'))->toBe(['summary', 'category']);

    $component
        ->set('solarisPreviewData.selected', ['category'])
        ->call('solarisRefinePreview', 'Make it shorter');

    expect($component->get('solarisPreviewData.values.summary'))->toBe('S2')
        ->and($component->get('solarisPreviewData.selected'))->toBe(['category']);
});

it('ticks a field the refinement fills for the first time', function () {
    AiFormAction::fakePartial(['summary' => 'S1', 'category' => null])
        ->fakeRefinement(['summary' => 'S2', 'category' => 'tech']);

    $component = Livewire::test(ConversationalFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateSelectable');

    expect($component->get('solarisPreviewData.selectable'))->toBe(['summary']);

    $component
        ->set('solarisPreviewData.selected', [])
        ->call('solarisRefinePreview', 'Also classify it');

    expect($component->get('solarisPreviewData.selectable'))->toBe(['summary', 'category'])
        ->and($component->get('solarisPreviewData.selected'))->toBe(['category']);
});

it('drops a field the refinement fails to fill', function () {
    AiFormAction::fake(['summary' => 'S1', 'category' => 'tech'])
        ->fakeRefinement(['summary' => 'S2', 'category' => null]);

    $component = Livewire::test(ConversationalFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateSelectable')
        ->call('solarisRefinePreview', 'Drop the category');

    expect($component->get('solarisPreviewData.selectable'))->toBe(['summary'])
        ->and($component->get('solarisPreviewData.selected'))->toBe(['summary']);
});
