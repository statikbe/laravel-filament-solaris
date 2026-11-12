<?php

use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Testing\AiFormActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\PreviewFormComponent;

beforeEach(function () {
    AiFormActionFake::reset();
    PreviewFormComponent::$written = [];
});

afterEach(function () {
    AiFormActionFake::reset();
});

function envelopeFake(string $key, mixed $value): array
{
    return [
        'summary' => ['value' => 'S', $key => $value],
        'category' => ['value' => 'tech', $key => $value],
    ];
}

it('picks a preset at runtime from the closure', function () {
    AiFormAction::fake(envelopeFake('evidence', 'derived'));

    $component = Livewire::test(PreviewFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateRuntimeMetadata');

    AiFormActionFake::getInstance()->assertCalledWith(
        fn ($sourceData, string $prompt) => str_contains($prompt, 'evidence: one of'),
    );

    AiFormActionFake::reset();
    AiFormAction::fake(envelopeFake('confidence', 0.9));

    $component->set('useConfidence', true)->callAction('generateRuntimeMetadata');

    AiFormActionFake::getInstance()->assertCalledWith(
        fn ($sourceData, string $prompt) => str_contains($prompt, 'confidence: a number from 0 to 1.'),
    );
});

it('turns metadata off when the closure returns null', function () {
    AiFormAction::fake(['summary' => 'S', 'category' => 'tech']);

    Livewire::test(PreviewFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateNullMetadata')
        ->assertFormSet(['summary' => 'S', 'category' => 'tech']);

    AiFormActionFake::getInstance()->assertCalledWith(
        fn ($sourceData, string $prompt) => ! str_contains($prompt, '## '.filament_solaris_trans('metadata.heading')),
    );
});

it('throws when the closure returns something else', function () {
    AiFormAction::fake(['summary' => 'S', 'category' => 'tech']);

    expect(fn () => Livewire::test(PreviewFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateInvalidMetadata'))
        ->toThrow(InvalidArgumentException::class, 'fieldMetadata() closure must return an array of keys, a FieldMetadata or null; got string.');

    AiFormActionFake::getInstance()->assertNotCalled();
});

it('injects the schema factory by type whatever the parameter name', function () {
    AiFormAction::fake(envelopeFake('evidence', 'x'));

    Livewire::test(PreviewFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateKeysOtherName')
        ->assertFormSet(['summary' => 'S', 'category' => 'tech']);
});

it('evaluates instruction closures with locale and source data', function () {
    AiFormAction::fake(envelopeFake('evidence', 'derived'));

    Livewire::test(PreviewFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('generateInstructionsClosure');

    AiFormActionFake::getInstance()->assertCalledWith(
        fn ($sourceData, string $prompt) => str_contains($prompt, 'Locale en for T'),
    );
});
