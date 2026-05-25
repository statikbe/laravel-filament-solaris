<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Statikbe\FilamentSolaris\Agents\SolarisAgent;

it('returns the custom schema resolver output when one is configured', function () {
    $agent = (new SolarisAgent)->configure('instruction', [], fn ($s) => ['foo' => $s->string()]);

    $result = $agent->schema(new JsonSchemaTypeFactory);

    expect($result)->toHaveKey('foo')
        ->and($result['foo'])->toBeInstanceOf(Type::class);
});

it('falls back to factory-derived schema when no resolver is set', function () {
    $agent = (new SolarisAgent)->configure('instruction', []);

    expect($agent->schema(new JsonSchemaTypeFactory))->toBe([]);
});
