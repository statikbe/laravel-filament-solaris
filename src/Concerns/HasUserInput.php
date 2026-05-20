<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Closure;
use Filament\Schemas\Components\Component;
use Statikbe\FilamentSolaris\Support\UserInput;

trait HasUserInput
{
    protected UserInput|Closure|null $userInput = null;

    protected bool $useDefaultUserInput = false;

    /**
     * Set the user input configuration.
     */
    public function userInput(UserInput|Closure $userInput): static
    {
        $this->userInput = $userInput;

        return $this;
    }

    /**
     * Opt in to the prompt builder's default user input.
     *
     * Resolution is deferred to {@see getUserInput()} so call order doesn't
     * matter — `->withDefaultUserInput()` works whether it runs before or
     * after `->prompt()` / `->preset()` (which set `$this->promptBuilder`).
     * An explicit `->userInput()` always takes precedence over the default.
     */
    public function withDefaultUserInput(): static
    {
        $this->useDefaultUserInput = true;

        return $this;
    }

    /**
     * Check if user input is configured.
     */
    public function hasUserInput(): bool
    {
        return $this->getUserInput() !== null;
    }

    public function getUserInput(): ?UserInput
    {
        if ($this->userInput !== null) {
            return value($this->userInput);
        }

        if ($this->useDefaultUserInput && isset($this->promptBuilder)) {
            return $this->promptBuilder->defaultUserInput();
        }

        return null;
    }

    /**
     * Get the Filament form schema for the user input modal.
     *
     * @return array<Component>
     */
    public function getUserInputFormSchema(): array
    {
        $userInput = $this->getUserInput();

        if ($userInput === null) {
            return [];
        }

        return $userInput->toFormSchema();
    }
}
