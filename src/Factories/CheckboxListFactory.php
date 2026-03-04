<?php

namespace Statikbe\FilamentSolaris\Factories;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;

class CheckboxListFactory extends ComponentFactory
{
    use Concerns\HasOptions;

    /**
     * {@inheritDoc}
     */
    public function responseSchema(JsonSchema $schema): Type
    {
        $options = $this->resolveOptions();
        $maxOptions = FilamentSolaris::config()->getMaxOptions();

        if (count($options) <= $maxOptions) {
            $keys = array_map('strval', array_keys($options));
            $description = collect($options)
                ->map(fn ($label, $key) => "{$key} ({$label})")
                ->implode(', ');

            return $schema->array()
                ->items($schema->string()->enum($keys))
                ->description($this->buildDescription("Options: {$description}"))
                ->required();
        }

        $sample = collect($options)->take(10)->values()->implode(', ');

        return $schema->array()
            ->items($schema->string())
            ->description($this->buildDescription(count($options)." available options. Examples: {$sample}."))
            ->required();
    }

    /**
     * {@inheritDoc}
     */
    public function toFormValue(mixed $aiValue): mixed
    {
        if ($aiValue === null) {
            return [];
        }

        // Wrap single string in array
        if (is_string($aiValue)) {
            $aiValue = [$aiValue];
        }

        if (! is_array($aiValue)) {
            return [];
        }

        $options = $this->resolveOptions();

        if (empty($options)) {
            return $aiValue;
        }

        $resolved = [];

        foreach ($aiValue as $value) {
            $key = $this->resolveOptionKey($value, $options);

            // Only include if it resolved to a valid option key
            if (array_key_exists($key, $options)) {
                $resolved[] = $key;
            } elseif (is_numeric($key) && array_key_exists((int) $key, $options)) {
                $resolved[] = (int) $key;
            } else {
                Log::warning("FilamentSolaris: Could not resolve checkbox list value '{$value}' to a valid option.");
            }
        }

        return $resolved;
    }

    /**
     * {@inheritDoc}
     */
    public function toPromptContext(mixed $formValue): mixed
    {
        if (empty($formValue)) {
            return 'None selected';
        }

        if (! is_array($formValue)) {
            return $formValue;
        }

        $options = $this->resolveOptions();

        return collect($formValue)
            ->map(fn ($key) => $options[$key] ?? $key)
            ->implode(', ');
    }
}
