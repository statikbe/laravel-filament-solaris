<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Closure;

trait HasSourceFields
{
    /**
     * @var array<string>|Closure
     */
    protected array|Closure $sourceFieldNames = [];

    /**
     * @var array<string, Closure>
     */
    protected array $sourceScopes = [];

    /**
     * Set the list of source field names.
     *
     * @param  array<string>|Closure  $fields
     */
    public function sourceFields(array|Closure $fields): static
    {
        $this->sourceFieldNames = $fields;

        return $this;
    }

    /**
     * @return array<string>
     */
    public function getSourceFields(): array
    {
        return value($this->sourceFieldNames);
    }

    /**
     * Register a value transformer for a specific source field.
     */
    public function sourceScope(string $field, Closure $scope): static
    {
        $this->sourceScopes[$field] = $scope;

        return $this;
    }

    /**
     * Collect values from the Livewire component's form state.
     *
     * @return array<string, mixed>
     */
    public function getSourceFieldValues(): array
    {
        $livewire = $this->getLivewire();
        $values = [];

        foreach ($this->getSourceFields() as $field) {
            $value = data_get($livewire->data ?? [], $field);

            if (isset($this->sourceScopes[$field])) {
                $value = ($this->sourceScopes[$field])($value);
            }

            $values[$field] = $value;
        }

        return $values;
    }
}
