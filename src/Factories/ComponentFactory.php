<?php

namespace Statikbe\FilamentSolaris\Factories;

use Closure;
use Filament\Schemas\Components\Component;
use Statikbe\FilamentSolaris\Contracts\ComponentFactory as ComponentFactoryContract;

abstract class ComponentFactory implements ComponentFactoryContract
{
    protected ?string $hint = null;

    public function __construct(
        protected Component $component,
        protected ?Closure $scope = null,
    ) {}

    /**
     * Set a behavioral hint that will be appended to the schema description.
     */
    public function hint(?string $hint): static
    {
        $this->hint = $hint;

        return $this;
    }

    /**
     * Build the final description by appending the hint (if set) to the structural description.
     */
    protected function buildDescription(string $structural): string
    {
        if (! $this->hint) {
            return $structural;
        }

        return $structural !== '' ? "{$structural} {$this->hint}" : $this->hint;
    }

    /**
     * Create a new factory instance.
     */
    public static function make(Component $component, ?Closure $scope = null): static
    {
        return new static($component, $scope);
    }

    /**
     * Get the Filament component this factory wraps.
     */
    public function getComponent(): Component
    {
        return $this->component;
    }

    /**
     * Get the optional scope closure.
     */
    public function getScope(): ?Closure
    {
        return $this->scope;
    }
}
