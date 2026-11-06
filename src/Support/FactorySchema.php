<?php

namespace Statikbe\FilamentSolaris\Support;

use Closure;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Statikbe\FilamentSolaris\Contracts\ComponentFactory;

/**
 * Turns target-field factories into a schema closure — the shape every
 * structured call accepts, so factory-derived and hand-written schemas can be
 * wrapped and passed around the same way.
 */
final class FactorySchema
{
    /**
     * @param  array<string, ComponentFactory>  $factories
     * @return Closure(JsonSchemaTypeFactory): array<string, Type>
     */
    public static function resolver(array $factories): Closure
    {
        return fn (JsonSchemaTypeFactory $schema): array => array_map(
            fn (ComponentFactory $factory): Type => $factory->responseSchema($schema),
            $factories,
        );
    }
}
