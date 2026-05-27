<?php

declare(strict_types=1);

namespace Statikbe\FilamentSolaris\Concerns;

use Closure;
use Statikbe\FilamentSolaris\Agents\SolarisAgent;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;

/**
 * Shared text-generation option setters + resolution + application.
 *
 * Used by {@see HasPromptPipeline} (which overrides resolveGenerationOptions()
 * for preset-aware fallback) and by {@see \Statikbe\FilamentSolaris\Actions\AiGenerateAction}.
 *
 * Setters accept Closure for runtime resolution via Filament's evaluate().
 * The base resolveGenerationOptions() falls back action → config default → null
 * (where null lets laravel/ai use its own #[Temperature] / provider defaults).
 */
trait HasGenerationOptions
{
    protected float|int|Closure|null $temperature = null;

    protected int|Closure|null $maxTokens = null;

    protected int|Closure|null $maxSteps = null;

    protected float|int|Closure|null $topP = null;

    public function temperature(float|int|Closure|null $temperature): static
    {
        $this->temperature = $temperature;

        return $this;
    }

    public function maxTokens(int|Closure|null $maxTokens): static
    {
        $this->maxTokens = $maxTokens;

        return $this;
    }

    public function maxSteps(int|Closure|null $maxSteps): static
    {
        $this->maxSteps = $maxSteps;

        return $this;
    }

    public function topP(float|int|Closure|null $topP): static
    {
        $this->topP = $topP;

        return $this;
    }

    /**
     * Resolve text-generation options for the AI call.
     *
     * Default chain per option (highest to lowest):
     * 1. Action setter
     * 2. Config default_{temperature|max_tokens|max_steps|top_p}
     * 3. null (laravel/ai falls back to its own attribute defaults)
     *
     * Consumers with richer chains (e.g. preset-aware) override this.
     *
     * @return array{temperature: ?float, max_tokens: ?int, max_steps: ?int, top_p: ?float}
     */
    protected function resolveGenerationOptions(): array
    {
        $config = FilamentSolaris::config();

        $temperature = $this->evaluate($this->temperature) ?? $config->getDefaultTemperature();
        $maxTokens = $this->evaluate($this->maxTokens) ?? $config->getDefaultMaxTokens();
        $maxSteps = $this->evaluate($this->maxSteps) ?? $config->getDefaultMaxSteps();
        $topP = $this->evaluate($this->topP) ?? $config->getDefaultTopP();

        return [
            'temperature' => $temperature !== null ? (float) $temperature : null,
            'max_tokens' => $maxTokens,
            'max_steps' => $maxSteps,
            'top_p' => $topP !== null ? (float) $topP : null,
        ];
    }

    /**
     * Push the resolved options onto the agent (no-op for any option resolved to null).
     */
    protected function applyGenerationOptions(SolarisAgent $agent): void
    {
        $opts = $this->resolveGenerationOptions();

        $agent
            ->withTemperature($opts['temperature'])
            ->withMaxTokens($opts['max_tokens'])
            ->withMaxSteps($opts['max_steps'])
            ->withTopP($opts['top_p']);
    }
}
