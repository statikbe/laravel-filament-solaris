<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Statikbe\FilamentSolaris\Support\UserInput;

/**
 * Adds preset-default-user-input behaviour to a consumer that already uses
 * {@see HasUserInput} AND owns a `$promptBuilder` property (i.e.
 * {@see HasPromptPipeline}).
 *
 * Adopters must resolve the trait collision on
 * `getDefaultUserInputFromBuilder()` via `insteadof`, replacing the no-op
 * from {@see HasUserInput} with the real implementation below.
 */
trait HasDefaultUserInput
{
    protected bool $useDefaultUserInput = false;

    /**
     * Opt in to the prompt builder's default user input.
     *
     * Resolution is deferred to {@see HasUserInput::getUserInput()} so call
     * order doesn't matter — `->withDefaultUserInput()` works whether it
     * runs before or after `->prompt()` / `->preset()` (which set
     * `$this->promptBuilder`). An explicit `->userInput()` always takes
     * precedence over the default.
     */
    public function withDefaultUserInput(): static
    {
        $this->useDefaultUserInput = true;

        return $this;
    }

    /**
     * Hook called by {@see HasUserInput::getUserInput()} when no explicit
     * `->userInput()` was set. Reads the preset's defaultUserInput()
     * iff the flag is on and a prompt builder is present.
     */
    protected function getDefaultUserInputFromBuilder(): ?UserInput
    {
        if ($this->useDefaultUserInput && isset($this->promptBuilder)) {
            return $this->promptBuilder->defaultUserInput();
        }

        return null;
    }
}
