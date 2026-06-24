<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Exceptions\AiException;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;
use Statikbe\FilamentSolaris\Agents\SolarisAgent;
use Statikbe\FilamentSolaris\Tests\Fixtures\GenerateFormComponent;

it('runs the real single-call path through AiGenerator and hands data to the handler', function () {
    SolarisAgent::fake([
        ['summary' => 'All good'],
    ]);

    $captured = null;

    $action = AiGenerateAction::make('summarise')
        ->prompt('Summarise this.')
        ->outputSchema(fn (JsonSchemaTypeFactory $s) => ['summary' => $s->string()])
        ->handleUsing(function (array $data) use (&$captured) {
            $captured = $data;
        });

    $action->execute();

    expect($captured)->toBe(['summary' => 'All good']);
    SolarisAgent::assertPrompted(fn ($prompt) => $prompt->contains('Summarise this.'));
});

it('sends the error notification and skips the handler when the real call fails', function () {
    // SolarisAgent::fake (not AiGenerateAction::fake) drives the REAL path: the
    // agent throws, AiGenerator rethrows AiException, and runSingleCallViaGenerator
    // catches it → error notification + the handler never runs.
    SolarisAgent::fake(fn () => throw new AiException('provider down'));

    Livewire::test(GenerateFormComponent::class)
        ->callAction('buildTaxonomy')
        ->assertNotified()
        ->assertSet('handledData', []);
});
