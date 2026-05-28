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

        return $this->getDefaultUserInputFromBuilder();
    }

    /**
     * Hook for optional preset-default user-input resolution.
     *
     * Returns null by default. Replaced via `insteadof` by
     * {@see HasDefaultUserInput::getDefaultUserInputFromBuilder()} on consumers
     * that adopt both traits (i.e. {@see HasPromptPipeline}).
     */
    protected function getDefaultUserInputFromBuilder(): ?UserInput
    {
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
