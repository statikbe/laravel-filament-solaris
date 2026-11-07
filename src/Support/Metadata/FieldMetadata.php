<?php

namespace Statikbe\FilamentSolaris\Support\Metadata;

use Closure;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;

/**
 * Per-field metadata the model returns beside each value (spec 45).
 *
 * Extend it to define a reusable vocabulary: implement keys(), optionally
 * instructions(), and pass `YourMetadata::make()` to `->fieldMetadata()`.
 * Subclasses stay stateless, so one instance is safe to reuse across actions.
 */
abstract class FieldMetadata
{
    public static function make(): static
    {
        return new static;
    }

    /**
     * The metadata keys added beside `value` in every envelope.
     *
     * @return array<string, Type>
     */
    abstract public function keys(JsonSchemaTypeFactory $schema): array;

    /**
     * Prompt language explaining the keys; null for none.
     */
    public function instructions(): ?string
    {
        return null;
    }

    /**
     * The keys, checked: `value` is reserved for the field's own value.
     *
     * @return array<string, Type>
     *
     * @throws InvalidArgumentException
     */
    final public function resolveKeys(JsonSchemaTypeFactory $schema): array
    {
        $keys = $this->keys($schema);

        if (array_key_exists('value', $keys)) {
            throw new InvalidArgumentException('Field metadata cannot define a key named "value": the envelope uses it for the field\'s own value.');
        }

        return $keys;
    }

    /**
     * Wrap every field of a schema in an envelope: {value, ...keys}.
     *
     * @param  Closure(JsonSchemaTypeFactory): array<string, Type>  $resolver
     * @return Closure(JsonSchemaTypeFactory): array<string, Type>
     */
    final public function wrap(Closure $resolver): Closure
    {
        return function (JsonSchemaTypeFactory $schema) use ($resolver): array {
            $keys = $this->resolveKeys($schema);

            return array_map(
                // Strict mode needs every property required and the envelope
                // itself nullable + required.
                fn (Type $type): Type => $schema
                    ->object(['value' => $type->required(), ...$keys])
                    ->nullable()
                    ->required(),
                $resolver($schema),
            );
        };
    }

    /**
     * Split a response into plain values and per-field metadata. Only the
     * named fields are unwrapped; other keys (e.g. `_message`) pass through.
     * A malformed envelope becomes a null value, which counts as a failed field.
     *
     * @param  array<string, mixed>  $response
     * @param  array<int, string>  $fieldNames
     */
    final public function unwrap(array $response, array $fieldNames): UnwrappedResponse
    {
        $values = [];
        $metadata = [];

        foreach ($response as $key => $item) {
            if (! in_array($key, $fieldNames, true)) {
                $values[$key] = $item;

                continue;
            }

            if (! is_array($item) || ! array_key_exists('value', $item)) {
                $values[$key] = null;

                continue;
            }

            $values[$key] = $item['value'];
            unset($item['value']);

            if ($item !== []) {
                $metadata[$key] = $item;
            }
        }

        return new UnwrappedResponse($values, $metadata);
    }

    /**
     * The prompt fragment appended when metadata is on. `$instructions`
     * overrides this preset's own instructions().
     */
    final public function promptFragment(?string $instructions = null): string
    {
        $instructions ??= $this->instructions();
        $fragment = '## '.filament_solaris_trans('metadata.heading')."\n\n".filament_solaris_trans('metadata.prompt');

        return filled($instructions) ? $fragment."\n\n".$instructions : $fragment;
    }
}
