<?php

namespace Statikbe\FilamentSolaris\Support\Schema;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use ReflectionEnum;

final class BackedEnumType
{
    /**
     * A string/integer schema type restricted to a backed enum's values; null for a non-enum or unit enum.
     */
    public static function for(JsonSchemaTypeFactory $schema, string $enum): ?Type
    {
        if (! enum_exists($enum)) {
            return null;
        }

        $reflection = new ReflectionEnum($enum);

        if (! $reflection->isBacked()) {
            return null;
        }

        $type = $reflection->getBackingType()?->getName() === 'int' ? $schema->integer() : $schema->string();

        /** @var class-string<\BackedEnum> $enum */
        return $type->enum($enum);
    }
}
