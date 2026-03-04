<?php

namespace Statikbe\FilamentSolaris\Prompts\Presets;

use Closure;

class ClassificationPreset extends Preset
{
    protected bool $allowMultiple = false;

    protected string|Closure|null $context = null;

    /**
     * Set whether the AI can select multiple categories.
     */
    public function allowMultiple(bool $allowMultiple = true): static
    {
        $this->allowMultiple = $allowMultiple;

        return $this;
    }

    /**
     * Set additional context about the classification domain.
     */
    public function context(string|Closure $context): static
    {
        $this->context = $context;

        return $this;
    }

    public function getContext(): ?string
    {
        return value($this->context);
    }

    /**
     * {@inheritDoc}
     */
    protected function promptView(): string
    {
        return 'filament-solaris::prompts.classify';
    }

    /**
     * {@inheritDoc}
     */
    protected function viewData(): array
    {
        return [
            'allowMultiple' => $this->allowMultiple,
            'context' => $this->getContext(),
        ];
    }
}
