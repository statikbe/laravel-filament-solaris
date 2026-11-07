<?php

use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Testing\AiFormActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\PreviewFormComponent;

beforeEach(fn () => AiFormActionFake::reset());
afterEach(fn () => AiFormActionFake::reset());

function lockedPreview(): Testable
{
    AiFormAction::fake(['summary' => 'S', 'category' => 'tech', 'is_featured' => true]);

    return Livewire::test(PreviewFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateSelectable');
}

it('rejects a client update to a preview value', function () {
    expect(fn () => lockedPreview()->set('solarisPreviewData.values.summary', 'Hacked'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('rejects a client that replaces the whole preview state', function () {
    $component = lockedPreview();

    expect(fn () => $component->set('solarisPreviewData', ['values' => ['title' => 'Hacked']]))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('keeps the field selection client-editable', function () {
    lockedPreview()
        ->assertSet('solarisPreviewSelected', ['summary', 'category', 'is_featured'])
        ->set('solarisPreviewSelected', ['summary'])
        ->call('solarisAcceptPreview')
        ->assertFormSet(['summary' => 'S', 'category' => null, 'is_featured' => false])
        ->assertSet('solarisPreviewSelected', null);
});
