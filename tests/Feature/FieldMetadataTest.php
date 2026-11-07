<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Agents\SolarisAgent;
use Statikbe\FilamentSolaris\Testing\AiFormActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\PreviewFormComponent;

beforeEach(function () {
    AiFormActionFake::reset();
    PreviewFormComponent::$written = [];
});

afterEach(function () {
    AiFormActionFake::reset();
});

function callMetadataAction(string $action): Testable
{
    return Livewire::test(PreviewFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction($action);
}

function envelopes(): array
{
    return [
        'summary' => ['value' => 'S', 'evidence' => 'derived'],
        'category' => ['value' => 'tech', 'evidence' => 'verbatim'],
    ];
}

it('writes unwrapped values to the form', function () {
    AiFormAction::fake(envelopes());

    callMetadataAction('generateWithMetadata')
        ->assertFormSet(['summary' => 'S', 'category' => 'tech']);
});

it('passes values and metadata to afterFieldsWritten', function () {
    AiFormAction::fake(envelopes());

    callMetadataAction('generateWithMetadata');

    expect(PreviewFormComponent::$written)->toBe([[
        'values' => ['summary' => 'S', 'category' => 'tech'],
        'metadata' => ['summary' => ['evidence' => 'derived'], 'category' => ['evidence' => 'verbatim']],
    ]]);
});

it('drops metadata of failed fields', function () {
    AiFormAction::fake([...envelopes(), 'category' => null]);

    callMetadataAction('generateWithMetadata')
        ->assertFormSet(['summary' => 'S', 'category' => null]);

    expect(PreviewFormComponent::$written[0]['metadata'])->toBe(['summary' => ['evidence' => 'derived']]);
});

it('fails every field when the fake is not envelope-shaped', function () {
    AiFormAction::fake(['summary' => 'S', 'category' => 'tech']);

    callMetadataAction('generateWithMetadata')
        ->assertFormSet(['summary' => '', 'category' => null]);

    expect(PreviewFormComponent::$written)->toBe([]);
});

it('sanitizes the value only', function () {
    AiFormAction::fake([...envelopes(), 'summary' => ['value' => 'keep', 'evidence' => 'derived']]);

    callMetadataAction('generateWithMetadataSanitized')
        ->assertFormSet(['summary' => 'KEEP']);

    expect(PreviewFormComponent::$written[0]['metadata']['summary'])->toBe(['evidence' => 'derived']);
});

it('appends the metadata prompt fragment and instructions', function () {
    AiFormAction::fake(envelopes());

    callMetadataAction('generateWithMetadata');

    AiFormActionFake::getInstance()->assertCalledWith(function ($sourceData, string $prompt) {
        expect($prompt)->toContain(filament_solaris_trans('metadata.prompt'))
            ->toContain('Explain evidence.');
    });
});

it('puts the metadata fragment under its own heading', function () {
    AiFormAction::fake(envelopes());

    callMetadataAction('generateWithMetadata');

    AiFormActionFake::getInstance()->assertCalledWith(function ($sourceData, string $prompt) {
        expect($prompt)->toContain('## '.filament_solaris_trans('metadata.heading')."\n\n".filament_solaris_trans('metadata.prompt'));
    });
});

it('rejects a value metadata key before any AI call, also when faked', function () {
    AiFormAction::fake(envelopes());

    expect(fn () => callMetadataAction('generateWithValueKey'))
        ->toThrow(InvalidArgumentException::class, 'key named "value"');

    AiFormActionFake::getInstance()->assertNotCalled();
});

it('lets fieldMetadataInstructions override a preset', function () {
    AiFormAction::fake(envelopes());

    callMetadataAction('generateWithPresetOverride');

    AiFormActionFake::getInstance()->assertCalledWith(function ($sourceData, string $prompt) {
        expect($prompt)->toContain('Override')
            ->not->toContain('Preset evidence instructions.');
    });
});

it('calls afterFieldsWritten with empty metadata when metadata is off', function () {
    AiFormAction::fake(['summary' => 'S', 'category' => 'tech']);

    callMetadataAction('generatePlainCallback');

    expect(PreviewFormComponent::$written)->toBe([[
        'values' => ['summary' => 'S', 'category' => 'tech'],
        'metadata' => [],
    ]]);
});

it('leaves the prompt alone when metadata is off', function () {
    AiFormAction::fake(['summary' => 'S']);

    callMetadataAction('generateDirect');

    AiFormActionFake::getInstance()->assertCalledWith(function ($sourceData, string $prompt) {
        expect($prompt)->not->toContain(filament_solaris_trans('metadata.prompt'));
    });
});

it('sends the wrapped schema on the real path', function () {
    SolarisAgent::fake([envelopes()]);

    callMetadataAction('generateWithMetadata')
        ->assertFormSet(['summary' => 'S', 'category' => 'tech']);

    SolarisAgent::assertPrompted(function ($prompt) {
        $schema = $prompt->agent->schema(new JsonSchemaTypeFactory);

        return array_keys($schema['summary']->toArray()['properties']) === ['value', 'evidence'];
    });
});
