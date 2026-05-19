<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Filament\Schemas\Components\Component;
use Statikbe\FilamentSolaris\Factories\ComponentFactory;
use Statikbe\FilamentSolaris\Support\SolarisNotification;

/**
 * Form-side helpers shared by every action that reads or writes a
 * Filament form schema.
 *
 * Used by the three current actions (AiAction, ImageGenerationAction,
 * DictationFieldAction). Future non-form actions (data import, report generation)
 * should NOT use this trait — their notion of "source" and "target" lives
 * elsewhere (an Eloquent model, a file path, a query).
 */
trait HasFormPipeline
{
    /**
     * Names of fields the action reads or writes — used by the form schema
     * resolver to locate a usable Filament component.
     *
     * Each consuming pipeline trait satisfies this contract: text pipelines
     * return target (and source) field names, image pipeline returns the
     * single target field as a one-element array.
     *
     * @return array<string>
     */
    abstract protected function getFieldNamesForSchemaResolution(): array;

    /**
     * Resolve a human-readable label for a field name from the form schema.
     *
     * Falls back to the field name in headline case when the component
     * isn't resolvable (relation managers, table actions, standalone components).
     */
    protected function resolveFieldLabel(string $fieldName): string
    {
        try {
            $label = $this->getLivewire()
                ->getSchemaComponent("form.{$fieldName}")
                ?->getLabel();

            if (filled($label)) {
                return (string) $label;
            }
        } catch (\Throwable) {
            // Component not resolvable
        }

        return str($fieldName)->headline()->toString();
    }

    /**
     * Resolve a form schema component for the Get/Set utilities.
     *
     * Prefers the action's own attached component; falls back to looking up
     * the first field returned by {@see getFieldNamesForSchemaResolution()}
     * on the Livewire component's form schema.
     */
    protected function resolveFormSchemaComponent(): ?Component
    {
        $schemaComponent = $this->getSchemaComponent();

        if ($schemaComponent !== null) {
            return $schemaComponent;
        }

        $livewire = $this->getLivewire();
        $fieldName = $this->getFieldNamesForSchemaResolution()[0] ?? null;

        if ($fieldName === null) {
            return null;
        }

        $component = $livewire->getSchemaComponent("form.{$fieldName}");

        return $component instanceof Component ? $component : null;
    }

    /**
     * Build display arrays (label + preview content) for the preview modal,
     * one per resolved factory.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, ComponentFactory>  $factories
     * @return array<string, array{label: string, display: string, type: string}>
     */
    protected function buildDisplays(array $values, array $factories): array
    {
        $displays = [];

        foreach ($values as $fieldName => $formValue) {
            $factory = $factories[$fieldName] ?? null;
            $preview = $factory?->toPreviewDisplay($formValue) ?? ['display' => (string) $formValue, 'type' => 'text'];

            $displays[$fieldName] = [
                'label' => $this->resolveFieldLabel($fieldName),
                'display' => $preview['display'],
                'type' => $preview['type'],
            ];
        }

        return $displays;
    }

    /**
     * Format a list of field labels for display in notifications.
     *
     * @param  array<string>  $labels
     */
    protected function formatFieldList(array $labels): string
    {
        return SolarisNotification::formatFieldList($labels);
    }
}
