<?php

use Statikbe\FilamentSolaris\Agents\SolarisAgent;

it('returns null temperature by default', function () {
    expect((new SolarisAgent)->temperature())->toBeNull();
});

it('records temperature via withTemperature', function () {
    $agent = (new SolarisAgent)->withTemperature(0.7);

    expect($agent->temperature())->toBe(0.7);
});

it('returns null maxTokens by default', function () {
    expect((new SolarisAgent)->maxTokens())->toBeNull();
});

it('records maxTokens via withMaxTokens', function () {
    $agent = (new SolarisAgent)->withMaxTokens(2048);

    expect($agent->maxTokens())->toBe(2048);
});

it('returns null maxSteps by default', function () {
    expect((new SolarisAgent)->maxSteps())->toBeNull();
});

it('records maxSteps via withMaxSteps', function () {
    $agent = (new SolarisAgent)->withMaxSteps(5);

    expect($agent->maxSteps())->toBe(5);
});

it('returns null topP by default', function () {
    expect((new SolarisAgent)->topP())->toBeNull();
});

it('records topP via withTopP', function () {
    $agent = (new SolarisAgent)->withTopP(0.9);

    expect($agent->topP())->toBe(0.9);
});

it('returns static for fluent chaining on with* methods', function () {
    $agent = new SolarisAgent;

    expect($agent->withTemperature(0.5))->toBe($agent)
        ->and($agent->withMaxTokens(100))->toBe($agent)
        ->and($agent->withMaxSteps(3))->toBe($agent)
        ->and($agent->withTopP(0.8))->toBe($agent);
});
