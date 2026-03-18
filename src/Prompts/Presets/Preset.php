<?php

namespace Statikbe\FilamentSolaris\Prompts\Presets;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Enums\Lab;
use Statikbe\FilamentSolaris\Prompts\AbstractPromptBuilder;
use Statikbe\FilamentSolaris\Support\UserInput;

abstract class Preset extends AbstractPromptBuilder
{
    /**
     * @var Lab|array<string, string>|array<int, string>|string|null
     */
    protected Lab|array|string|null $presetProvider = null;

    protected ?string $presetModel = null;

    /**
     * Create a new preset instance.
     */
    public static function make(): static
    {
        return new static;
    }

    /**
     * Set the provider (and optionally model) for this preset.
     *
     * @param  Lab|array<string, string>|array<int, string>|string  $provider
     */
    public function provider(Lab|array|string $provider, ?string $model = null): static
    {
        $this->presetProvider = $provider;

        if ($model !== null) {
            $this->presetModel = $model;
        }

        return $this;
    }

    /**
     * Get the preset-level provider.
     *
     * @return Lab|array<string, string>|array<int, string>|string|null
     */
    public function getProvider(): Lab|array|string|null
    {
        return $this->presetProvider;
    }

    /**
     * Get the preset-level model.
     */
    public function getModel(): ?string
    {
        return $this->presetModel;
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
