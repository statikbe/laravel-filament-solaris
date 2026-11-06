<?php

namespace Statikbe\FilamentSolaris\Support\Metadata;

use Closure;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;

/**
 * Backs the inline `->fieldMetadata(fn ($schema) => [...])` form. Action-level
 * instructions reach the prompt through promptFragment()'s override.
 */
final class ClosureFieldMetadata extends FieldMetadata
{
    /**
     * @param  Closure(JsonSchemaTypeFactory): array<string, Type>  $keys
     */
    public function __construct(private readonly Closure $keys) {}

    public function keys(JsonSchemaTypeFactory $schema): array
    {
        return ($this->keys)($schema);
    }
}
