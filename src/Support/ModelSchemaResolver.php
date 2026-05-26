<?php

namespace Statikbe\FilamentSolaris\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Schema;
use ReflectionEnum;
use Statikbe\FilamentSolaris\Contracts\ComponentFactory;

/**
 * Introspects an Eloquent model's writable columns into a JSON-schema property
 * map — the model-side analogue of a {@see ComponentFactory}.
 *
 * v1 "simplified blend": DB columns + light cast refinement, honouring
 * fillable/guarded and auto-excluding the primary key, timestamps, and the
 * soft-delete column. Backed-enum casts produce a typed `enum` constraint.
 * Per-column `$hints` / `$enums` overrides come from `AiGenerateAction`.
 */
class ModelSchemaResolver
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string>  $only
     * @param  array<string>  $except
     * @param  array<string, string>  $hints  column => description text
     * @param  array<string, array<int, mixed>>  $enums  column => allowed values (manual override; beats cast)
     * @return array<string, Type>
     */
    public function resolve(
        JsonSchemaTypeFactory $schema,
        string $modelClass,
        array $only = [],
        array $except = [],
        array $hints = [],
        array $enums = [],
    ): array {
        $model = new $modelClass;
        $columns = Schema::getColumns($model->getTable());

        $fillable = $model->getFillable();
        $guarded = $model->getGuarded();
        $autoExcluded = $this->autoExcludedColumns($model);
        $casts = $model->getCasts();

        $properties = [];

        foreach ($columns as $column) {
            $name = $column['name'];

            if (in_array($name, $autoExcluded, true)) {
                continue;
            }
            if ($fillable !== [] && ! in_array($name, $fillable, true)) {
                continue;
            }
            if ($fillable === [] && in_array($name, $guarded, true)) {
                continue;
            }
            if ($only !== [] && ! in_array($name, $only, true)) {
                continue;
            }
            if (in_array($name, $except, true)) {
                continue;
            }

            $type = $this->buildBaseType($schema, $column, $casts[$name] ?? null);

            if (isset($enums[$name])) {
                $type = $type->enum($enums[$name]);
            }

            if (isset($hints[$name])) {
                $type = $type->description($hints[$name]);
            }

            if (($column['nullable'] ?? false) === false) {
                $type = $type->required();
            }

            $properties[$name] = $type;
        }

        return $properties;
    }

    /**
     * Backed-enum cast → typed enum; otherwise the generic column/cast mapping.
     *
     * @param  array<string, mixed>  $column
     */
    protected function buildBaseType(JsonSchemaTypeFactory $schema, array $column, ?string $cast): Type
    {
        if ($cast !== null && enum_exists($cast)) {
            $reflection = new ReflectionEnum($cast);

            if ($reflection->isBacked()) {
                $backing = $reflection->getBackingType()?->getName();
                $type = $backing === 'int' ? $schema->integer() : $schema->string();

                /** @var class-string<\BackedEnum> $cast */
                return $type->enum($cast);
            }
            // Unit (non-backed) enums fall through to the generic mapping.
        }

        return $this->mapType($schema, $column['type_name'] ?? ($column['type'] ?? 'string'), $cast);
    }

    /**
     * @return array<string>
     */
    public function autoExcludedColumns(Model $model): array
    {
        $excluded = [$model->getKeyName()];

        if ($model->usesTimestamps()) {
            $excluded[] = $model->getCreatedAtColumn();
            $excluded[] = $model->getUpdatedAtColumn();
        }

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true) && method_exists($model, 'getDeletedAtColumn')) {
            $excluded[] = $model->getDeletedAtColumn();
        }

        return array_values(array_filter($excluded));
    }

    protected function mapType(JsonSchemaTypeFactory $schema, string $columnType, ?string $cast): Type
    {
        // Cast (when declared) refines the DB column type — the "blend".
        $key = strtolower($cast ?? $columnType);

        return match (true) {
            str_contains($key, 'bool'), str_contains($key, 'tinyint(1)') => $schema->boolean(),
            str_contains($key, 'int') => $schema->integer(),
            str_contains($key, 'decimal'), str_contains($key, 'float'),
            str_contains($key, 'double'), str_contains($key, 'numeric'), $key === 'real' => $schema->number(),
            default => $schema->string(),
        };
    }
}
