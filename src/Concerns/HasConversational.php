<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Closure;

trait HasConversational
{
    protected bool|Closure $conversational = false;

    /**
     * Enable conversational refinement mode.
     *
     * When enabled, the preview modal includes a chat interface for iterative
     * AI refinement. Implies `withPreview()`.
     */
    public function conversational(bool|Closure $conversational = true): static
    {
        $this->conversational = $conversational;

        if ($this->evaluate($conversational)) {
            $this->withPreview();
        }

        return $this;
    }

    /**
     * Check if conversational refinement is enabled.
     */
    public function isConversational(): bool
    {
        return (bool) $this->evaluate($this->conversational);
    }
}
