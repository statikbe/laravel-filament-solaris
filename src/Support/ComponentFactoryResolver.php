<?php

namespace Statikbe\FilamentSolaris\Support;

use Closure;
use Filament\Schemas\Components\Component;
use RuntimeException;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Statikbe\FilamentSolaris\Factories\ComponentFactory;

class ComponentFactoryResolver
{
    /**
     * Resolve a target field name to its factory instance.
     *
     * @param  array<Component>  $components  Flat list of form components
     * @param  string  $fieldName  The target field name
     * @param  Closure|null  $scope  Optional scope for the factory
     * @return ComponentFactory The resolved factory instance
     *
     * @throws RuntimeException
     */
    public function resolve(array $components, string $fieldName, ?Closure $scope = null): ComponentFactory
    {
        $component = $this->findComponent($components, $fieldName);

        if ($component === null) {
            throw new RuntimeException("Component '{$fieldName}' not found in form.");
        }

        $factoryClass = $this->getFactoryClass($component);

        return $factoryClass::make($component, $scope);
    }

    /**
     * Resolve multiple target fields to their factory instances.
     *
     * @param  array<Component>  $components  Flat list of form components
     * @param  array<string>  $fieldNames  List of target field names
     * @param  array<string, Closure>  $scopes  Keyed by field name
     * @return array<string, ComponentFactory>
     *
     * @throws RuntimeException
     */
    public function resolveMany(array $components, array $fieldNames, array $scopes = []): array
    {
        $factories = [];

        foreach ($fieldNames as $fieldName) {
            $factories[$fieldName] = $this->resolve(
                $components,
                $fieldName,
                $scopes[$fieldName] ?? null,
            );
        }

        return $factories;
    }

    /**
     * Find a component by state path or name.
     *
     * @param  array<Component>  $components
     */
    private function findComponent(array $components, string $fieldName): ?Component
    {
        // First try matching on getStatePath()
        foreach ($components as $component) {
            try {
                if ($component->getStatePath() === $fieldName) {
                    return $component;
                }
            } catch (\Error) {
                // Filament throws \Error (not \Exception) when getStatePath()
                // is called on a component without a container.
            }
        }

        // Fall back to getName()
        foreach ($components as $component) {
            if (method_exists($component, 'getName') && $component->getName() === $fieldName) {
                return $component;
            }
        }

        return null;
    }

    /**
     * Get the factory class for a given component.
     *
     * @return class-string<ComponentFactory>
     *
     * @throws RuntimeException
     */
    private function getFactoryClass(Component $component): string
    {
        $factoryMap = FilamentSolaris::config()->getResolvedFactoryMap();
        $componentClass = get_class($component);

        if (isset($factoryMap[$componentClass])) {
            return $factoryMap[$componentClass];
        }

        // Check parent classes for inheritance
        foreach (class_parents($component) as $parentClass) {
            if (isset($factoryMap[$parentClass])) {
                return $factoryMap[$parentClass];
            }
        }

        throw new RuntimeException(
            "No AI factory registered for {$componentClass}. Register one via FilamentSolaris::registerFactory()."
        );
    }
}
