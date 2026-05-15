<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Closure;
use Filament\Notifications\Notification;

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

    /**
     * Send a warning notification when every configured source field is empty.
     *
     * Returns true when the warning was sent so the caller can short-circuit
     * the pipeline. No-op + returns false when there are no source fields
     * configured or at least one has a value.
     *
     * Relies on {@see HasFormPipeline::resolveFieldLabel()} and
     * {@see HasFormPipeline::formatFieldList()} — the consuming class is
     * expected to compose both traits.
     *
     * @param  array<string, mixed>  $sourceData
     */
    protected function warnIfSourceFieldsEmpty(array $sourceData): bool
    {
        $sourceFields = $this->getSourceFields();

        if (empty($sourceFields)) {
            return false;
        }

        if (collect($sourceData)->contains(fn (mixed $value): bool => filled($value))) {
            return false;
        }

        $labels = array_map(
            fn (string $field): string => $this->resolveFieldLabel($field),
            $sourceFields,
        );

        Notification::make()
            ->title(filament_solaris_trans_choice('notifications.empty_source_fields', count($labels), [
                'fields' => $this->formatFieldList($labels),
            ]))
            ->warning()
            ->send();

        return true;
    }
}
