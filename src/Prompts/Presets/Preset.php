<?php

namespace Statikbe\FilamentSolaris\Prompts\Presets;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentSolaris\Prompts\AbstractPromptBuilder;
use Statikbe\FilamentSolaris\Support\UserInput;

abstract class Preset extends AbstractPromptBuilder
{
    /**
     * Create a new preset instance.
     */
    public static function make(): static
    {
        return new static;
    }

    /**
     * The Blade view path for this preset's prompt template.
     */
    abstract protected function promptView(): string;

    /**
     * Preset-specific variables passed to the Blade template.
     *
     * @return array<string, mixed>
     */
    abstract protected function viewData(): array;

    /**
     * {@inheritDoc}
     */
    public function defaultUserInput(): ?UserInput
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function build(
        string|View $instruction,
        array $sourceData,
        array $factories,
        ?Model $record = null,
        ?string $locale = null,
        array $userInput = [],
    ): string {
        $data = $this->buildViewData($instruction, $sourceData, $factories, $record, $locale, $userInput);

        // Remove instruction — presets use their own promptView()
        unset($data['instruction']);

        return view($this->promptView(), [
            ...$data,
            ...$this->viewData(),
        ])->render();
    }
}
