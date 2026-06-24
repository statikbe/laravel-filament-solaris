<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;
use Statikbe\FilamentSolaris\Agents\SolarisAgent;

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
    SolarisAgent::assertPrompted(fn ($prompt) => true);
});
