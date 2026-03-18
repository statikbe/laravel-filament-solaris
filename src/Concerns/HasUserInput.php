<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Closure;
use Filament\Schemas\Components\Component;
use Statikbe\FilamentSolaris\Support\UserInput;

trait HasUserInput
{
    protected UserInput|Closure|null $userInput = null;

    /**
     * Set the user input configuration.
     */
    public function userInput(UserInput|Closure $userInput): static
    {
        $this->userInput = $userInput;

        return $this;
    }

    /**
     * Use the prompt builder's default user input, if available.
     */
    public function withDefaultUserInput(): static
    {
        if ($this->userInput !== null) {
            return $this;
        }

        if (isset($this->promptBuilder)) {
            $defaultInput = $this->promptBuilder->defaultUserInput();

            if ($defaultInput !== null) {
                $this->userInput = $defaultInput;
            }
        }

        return $this;
    }

    /**
     * Check if user input is configured.
     */
    public function hasUserInput(): bool
    {
        return $this->userInput !== null;
    }

    public function getUserInput(): ?UserInput
    {
        return value($this->userInput);
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
