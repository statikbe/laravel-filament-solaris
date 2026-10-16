<?php

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

it('is off by default', function () {
    $action = AiFormAction::make('test')->targetFields(['summary'])->prompt('p')->withPreview();

    expect($action->getSelectablePreviewFields())->toBeNull();
});

it('implies the preview', function () {
    $action = AiFormAction::make('test')->targetFields(['summary'])->prompt('p')->withSelectablePreview();

    expect($action->shouldPreview())->toBeTrue();
});

it('makes every target field selectable when given true', function () {
    $action = AiFormAction::make('test')->targetFields(['summary', 'category'])->prompt('p')->withSelectablePreview();

    expect($action->getSelectablePreviewFields())->toBe(['summary', 'category']);
});

it('makes only the listed fields selectable when given a subset', function () {
    $action = AiFormAction::make('test')->targetFields(['summary', 'category'])->prompt('p')
        ->withSelectablePreview(['category']);

    expect($action->getSelectablePreviewFields())->toBe(['category']);
});

it('turns selection off with false and leaves the preview on', function () {
    $action = AiFormAction::make('test')->targetFields(['summary'])->prompt('p')
        ->withPreview()
        ->withSelectablePreview(false);

    expect($action->getSelectablePreviewFields())->toBeNull()
        ->and($action->shouldPreview())->toBeTrue();
});

it('throws before any AI call when the subset names an unknown field', function () {
    AiFormAction::fake(['summary' => 'S']);

    expect(fn () => Livewire::test(PreviewFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateUnknownSubset'))
        ->toThrow(RuntimeException::class, 'not target fields: nope');

    AiFormAction::assertNotCalled();
});

it('throws when the subset is empty', function () {
    AiFormAction::fake(['summary' => 'S']);

    expect(fn () => Livewire::test(PreviewFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateEmptySubset'))
        ->toThrow(RuntimeException::class, 'needs at least one field');

    AiFormAction::assertNotCalled();
});
