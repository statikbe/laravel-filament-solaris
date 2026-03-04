<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Closure;
use Statikbe\FilamentSolaris\Factories\ComponentFactory;
use Statikbe\FilamentSolaris\Support\ComponentFactoryResolver;

trait HasTargetFields
{
    /**
     * @var array<string>|Closure
     */
    protected array|Closure $targetFieldNames = [];

    /**
     * @var array<string, Closure>
     */
    protected array $targetScopes = [];

    /**
     * @var array<string, string>
     */
    protected array $targetHints = [];

    protected bool $preview = false;

    /**
     * Add a target field. Can be called multiple times.
     */
    public function targetField(string $field): static
    {
        $resolved = value($this->targetFieldNames);
        $resolved[] = $field;
        $this->targetFieldNames = $resolved;

        return $this;
    }

    /**
     * Set multiple target fields at once. Replaces previously set targets.
     *
     * @param  array<string>|Closure  $fields
     */
    public function targetFields(array|Closure $fields): static
    {
        $this->targetFieldNames = $fields;

        return $this;
    }

    /**
     * @return array<string>
     */
    public function getTargetFields(): array
    {
        return value($this->targetFieldNames);
    }

    /**
     * Register a query scope for a target field's factory.
     */
    public function targetScope(string $field, Closure $scope): static
    {
        $this->targetScopes[$field] = $scope;

        return $this;
    }

    /**
     * Set a behavioral hint for a target field's schema description.
     */
    public function targetHint(string $field, string $hint): static
    {
        $this->targetHints[$field] = $hint;

        return $this;
    }

    /**
     * Enable or disable the preview modal.
     */
    public function withPreview(bool $preview = true): static
    {
        $this->preview = $preview;

        return $this;
    }

    /**
     * Resolve target factories for all registered target fields.
     *
     * @return array<string, ComponentFactory>
     */
    public function resolveTargetFactories(): array
    {
        $livewire = $this->getLivewire();

        $components = collect($livewire->getCachedSchemas())
            ->filter()
            ->flatMap(fn ($schema) => $schema->getFlatComponents())
            ->all();

        $resolver = app(ComponentFactoryResolver::class);

        $factories = $resolver->resolveMany($components, $this->getTargetFields(), $this->targetScopes);

        foreach ($this->targetHints as $field => $hint) {
            if (isset($factories[$field])) {
                $factories[$field]->hint($hint);
            }
        }

        return $factories;
    }
}
