<?php

namespace Statikbe\FilamentSolaris\Factories;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;

class SelectFactory extends ComponentFactory
{
    use Concerns\HasOptions;

    /**
     * {@inheritDoc}
     */
    public function responseSchema(JsonSchema $schema): Type
    {
        $options = $this->resolveOptions();
        $maxOptions = FilamentSolaris::config()->getMaxOptions();
        $multiple = $this->isMultiple();

        if (count($options) <= $maxOptions) {
            $keys = array_map('strval', array_keys($options));
            $description = collect($options)
                ->map(fn ($label, $key) => "{$key} ({$label})")
                ->implode(', ');

            $desc = $this->buildDescription("Options: {$description}");

            if ($multiple) {
                return $schema->array()
                    ->items($schema->string()->enum($keys))
                    ->description($desc)
                    ->required();
            }

            return $schema->string()
                ->enum($keys)
                ->description($desc)
                ->required();
        }

        if (count($options) > 500) {
            Log::warning('FilamentSolaris: Select field has more than 500 options. Consider using a scoped query.');
        }

        $sample = collect($options)->take(10)->values()->implode(', ');
        $desc = $this->buildDescription(count($options)." available options. Examples: {$sample}.");

        if ($multiple) {
            return $schema->array()
                ->items($schema->string())
                ->description($desc)
                ->required();
        }

        return $schema->string()
            ->description($desc)
            ->required();
    }

    /**
     * {@inheritDoc}
     */
    public function toFormValue(mixed $aiValue): mixed
    {
        if ($aiValue === null) {
            return $this->isMultiple() ? [] : null;
        }

        $options = $this->resolveOptions();

        if (empty($options)) {
            return $aiValue;
        }

        if ($this->isMultiple()) {
            $values = is_array($aiValue) ? $aiValue : [$aiValue];

            return array_values(array_filter(
                array_map(fn ($v) => $this->resolveOptionKey($v, $options), $values),
                fn ($v) => array_key_exists($v, $options)
                    || (is_numeric($v) && array_key_exists((int) $v, $options)),
            ));
        }

        return $this->resolveOptionKey($aiValue, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function toPromptContext(mixed $formValue): mixed
    {
        if ($formValue === null) {
            return $formValue;
        }

        $options = $this->resolveOptions();

        if (is_array($formValue)) {
            return collect($formValue)
                ->map(fn ($key) => $options[$key] ?? $key)
                ->implode(', ');
        }

        return $options[$formValue] ?? $formValue;
    }

    protected function isMultiple(): bool
    {
        return method_exists($this->component, 'isMultiple') && $this->component->isMultiple();
    }
}
