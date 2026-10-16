<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Testing\AiFormActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\PreviewFormComponent;

beforeEach(function () {
    AiFormActionFake::reset();
});

afterEach(function () {
    AiFormActionFake::reset();
});

$displays = [
    'summary' => ['label' => 'Summary', 'display' => 'S', 'type' => 'text'],
    'is_featured' => ['label' => 'Is featured', 'display' => 'Yes', 'type' => 'text'],
];

it('renders a checkbox only for selectable items', function () use ($displays) {
    $html = Blade::render(
        '<x-filament-solaris::preview-fields :displays="$displays" :selectable="$selectable" />',
        ['displays' => $displays, 'selectable' => ['summary']],
    );

    expect($html)->toContain('wire:model="solarisPreviewData.selected"')
        ->and($html)->toContain('value="summary"')
        ->and($html)->not->toContain('value="is_featured"');
});

it('renders no checkboxes when selection is off', function () use ($displays) {
    $html = Blade::render('<x-filament-solaris::preview-fields :displays="$displays" />', ['displays' => $displays]);

    expect($html)->not->toContain('solarisPreviewData.selected');
});

it('binds Accept to the selection when every item is selectable', function () {
    $html = view('filament-solaris::preview-modal-footer', ['requiresSelection' => true])->render();

    expect($html)->toContain('x-bind:disabled="! ($wire.solarisPreviewData?.selected ?? []).length"');
});

it('keeps Accept enabled when some items are always written', function () {
    $html = view('filament-solaris::preview-modal-footer', ['requiresSelection' => false])->render();

    expect($html)->toContain('x-bind:disabled="false"');
});

it('passes the selection into the mounted preview modal', function () {
    AiFormAction::fake(['summary' => 'S', 'category' => 'tech', 'is_featured' => true]);

    $component = Livewire::test(PreviewFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateSelectable');

    $action = $component->instance()->getMountedAction();

    expect($action->getModalContent()->getData()['selectable'])->toBe(['summary', 'category', 'is_featured'])
        ->and($action->getModalContentFooter()->getData()['requiresSelection'])->toBeTrue();
});
