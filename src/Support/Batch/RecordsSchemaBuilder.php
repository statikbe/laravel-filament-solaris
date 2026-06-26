<?php

namespace Statikbe\FilamentSolaris\Support\Batch;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Statikbe\FilamentSolaris\Support\ModelSchemaResolver;

/**
 * Wraps a model's resolved property map (from {@see ModelSchemaResolver}) in the
 * `records[]` + `failed[]` envelope the records-loop and single-call structured
 * responses share, including the synthetic identifier column the AI echoes back
 * (`_index` for positional matching, or the model's primary key for updates).
 *
 * Filament-free: reused by the inline batch path, forModel single-call, and the
 * queued worker.
 */
class RecordsSchemaBuilder
{
    /**
     * @param  class-string  $modelClass
     * @param  array<string>  $only
     * @param  array<string>  $except
     * @param  array<string, string>  $hints
     * @param  array<string, array<int, mixed>>  $enums
     * @return array{records: Type, failed: Type}
     */
    public function build(
        JsonSchemaTypeFactory $schema,
        string $modelClass,
        string $identifierKey,
        array $only = [],
        array $except = [],
        array $hints = [],
        array $enums = [],
    ): array {
        $properties = (new ModelSchemaResolver)->resolve(
            $schema,
            $modelClass,
            $only,
            $except,
            $hints,
            $enums,
        );

        $properties[$identifierKey] = $identifierKey === '_index'
            ? $schema->integer()->description('The _index field from the input record. Echo unchanged.')
            : $schema->integer()->description('The primary key. Echo unchanged.');

        return [
            'records' => $schema->array()->items($schema->object($properties)),
            'failed' => $schema->array()->items($schema->object([
                'identifier' => $schema->string()->description('Identifier of the failed input row (or freeform description in single-call mode).'),
                'reason' => $schema->string()->description('Short reason for the failure (max 200 chars).'),
            ])),
        ];
    }
}
