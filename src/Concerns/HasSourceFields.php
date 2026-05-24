<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Closure;
use Filament\Notifications\Notification;
use Statikbe\FilamentSolaris\Factories\ComponentFactory;
use Statikbe\FilamentSolaris\Factories\TextFactory;
use Statikbe\FilamentSolaris\Support\ComponentFactoryResolver;

/**
 * Source-field declaration + value collection for form-targeting actions.
 *
 * Composes with {@see HasFormPipeline} — relies on its
 * `resolveFormSchemaComponent()` and `resolveFieldLabel()` / `formatFieldList()`
 * for schema-based value reads and the empty-fields warning. The three
 * concrete actions that use source fields (AiAction, ImageGenerationAction)
 * all also use HasFormPipeline; standalone usage would need to provide
 * those methods independently.
 */
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
        return $this->evaluate($this->sourceFieldNames);
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
     * Each source field is read through its factory's
     * {@see ComponentFactory::readState()},
     * which defaults to Filament's schema-based `Get` utility (so reads work
     * in relation managers, table actions, and any nested schema where the
     * canonical state isn't at `$livewire->data`). File-shaped factories
     * (`FileUploadFactory`, etc.) override `readState` to return the raw
     * Livewire state shape — using a FileUpload as a source field stays
     * meaningful even though it's unusual.
     *
     * Falls back to {@see TextFactory} when a field isn't bound to a known
     * component (e.g. computed fields that don't appear in the form schema)
     * — TextFactory's read goes through `Get` with `$livewire->data`
     * fallback, matching the previous direct-data_get behaviour.
     *
     * @return array<string, mixed>
     */
    public function getSourceFieldValues(): array
    {
        $livewire = $this->getLivewire();
        $schemaComponent = $this->resolveFormSchemaComponent();
        $components = collect($livewire->getCachedSchemas())
            ->filter()
            ->flatMap(fn ($schema) => $schema->getFlatComponents())
            ->all();
        $resolver = app(ComponentFactoryResolver::class);

        $values = [];

        foreach ($this->getSourceFields() as $field) {
            $factoryClass = $resolver->resolveFactoryClassForField($components, $field) ?? TextFactory::class;
            $value = $factoryClass::readState($livewire, $field, $schemaComponent);

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
