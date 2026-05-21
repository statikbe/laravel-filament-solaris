<?php

namespace Statikbe\FilamentSolaris\Prompts\Presets;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Enums\Lab;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Statikbe\FilamentSolaris\Prompts\AbstractPromptBuilder;
use Statikbe\FilamentSolaris\Support\UserInput;

abstract class Preset extends AbstractPromptBuilder
{
    /**
     * @var Lab|array<string, string>|array<int, string>|string|null
     */
    protected Lab|array|string|null $presetProvider = null;

    protected ?string $presetModel = null;

    protected ?float $presetTemperature = null;

    protected ?int $presetMaxTokens = null;

    protected ?int $presetMaxSteps = null;

    protected ?float $presetTopP = null;

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

    public function temperature(?float $temperature): static
    {
        $this->presetTemperature = $temperature;

        return $this;
    }

    public function maxTokens(?int $maxTokens): static
    {
        $this->presetMaxTokens = $maxTokens;

        return $this;
    }

    public function maxSteps(?int $maxSteps): static
    {
        $this->presetMaxSteps = $maxSteps;

        return $this;
    }

    public function topP(?float $topP): static
    {
        $this->presetTopP = $topP;

        return $this;
    }

    public function getTemperature(): ?float
    {
        return $this->presetTemperature;
    }

    public function getMaxTokens(): ?int
    {
        return $this->presetMaxTokens;
    }

    public function getMaxSteps(): ?int
    {
        return $this->presetMaxSteps;
    }

    public function getTopP(): ?float
    {
        return $this->presetTopP;
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
     * Resolve a configured output language to a human-readable name for the
     * prompt instruction.
     *
     * Accepts a locale code (`fr`, `nl`) — resolved to its English display
     * name (`French`, `Dutch`) so the instruction reads naturally — or a name
     * already in display form, which passes through unchanged. Mirrors the
     * `locale` → `localeName` resolution applied to the action locale.
     */
    protected function resolveLanguageName(?string $language): ?string
    {
        if ($language === null) {
            return null;
        }

        return FilamentSolaris::config()->resolveLocaleName($language, 'en');
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
