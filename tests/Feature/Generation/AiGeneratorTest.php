<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Exceptions\AiException;
use Statikbe\FilamentSolaris\Agents\SolarisAgent;
use Statikbe\FilamentSolaris\Events\SolarisResponseFailed;
use Statikbe\FilamentSolaris\Events\SolarisResponseReceived;
use Statikbe\FilamentSolaris\Generation\AiGenerator;
use Statikbe\FilamentSolaris\Generation\GenerationResult;

it('runs a structured call and returns the parsed data', function () {
    SolarisAgent::fake([
        ['title' => 'Hello', 'score' => 7],
    ]);

    $result = AiGenerator::make()
        ->prompt('Generate a title and score.')
        ->schema(fn (JsonSchemaTypeFactory $s) => [
            'title' => $s->string(),
            'score' => $s->integer(),
        ])
        ->runInline();

    expect($result)->toBeInstanceOf(GenerationResult::class)
        ->and($result->data)->toBe(['title' => 'Hello', 'score' => 7]);

    SolarisAgent::assertPrompted(fn ($prompt) => $prompt->contains('Generate a title and score.'));
});

it('dispatches SolarisResponseReceived on success', function () {
    Event::fake([SolarisResponseReceived::class]);
    SolarisAgent::fake([['title' => 'X']]);

    AiGenerator::make()
        ->prompt('p')
        ->eventSource('plausibility-check')
        ->schema(fn (JsonSchemaTypeFactory $s) => ['title' => $s->string()])
        ->runInline();

    Event::assertDispatched(SolarisResponseReceived::class, fn ($e) => $e->actionName === 'plausibility-check');
});

it('dispatches SolarisResponseFailed and throws AiException on failure', function () {
    Event::fake([SolarisResponseFailed::class]);
    SolarisAgent::fake(fn () => throw new AiException('boom'));

    $run = fn () => AiGenerator::make()
        ->prompt('p')
        ->schema(fn (JsonSchemaTypeFactory $s) => ['title' => $s->string()])
        ->runInline();

    expect($run)->toThrow(AiException::class, 'boom');

    Event::assertDispatched(SolarisResponseFailed::class);
});

it('wraps a non-AiException throwable into an AiException', function () {
    SolarisAgent::fake(fn () => throw new RuntimeException('provider exploded'));

    $run = fn () => AiGenerator::make()
        ->prompt('p')
        ->schema(fn (JsonSchemaTypeFactory $s) => ['title' => $s->string()])
        ->runInline();

    expect($run)->toThrow(AiException::class, 'provider exploded');
});
