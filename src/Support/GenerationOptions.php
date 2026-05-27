<?php

namespace Statikbe\FilamentSolaris\Support;

use Statikbe\FilamentSolaris\Agents\SolarisAgent;
use Statikbe\FilamentSolaris\Concerns\HasGenerationOptions;
use Statikbe\FilamentSolaris\Concerns\HasPromptPipeline;

/**
 * Resolved text-generation options for a single AI call.
 *
 * Built by {@see HasGenerationOptions::resolveGenerationOptions()}
 * (and the preset-aware override in {@see HasPromptPipeline}),
 * then pushed onto the agent via {@see self::applyTo()}.
 *
 * Any option resolved to null is a no-op on the agent — laravel/ai falls back
 * to its own #[Temperature] / #[MaxTokens] / #[MaxSteps] / #[TopP] attributes
 * and ultimately to provider defaults.
 */
final readonly class GenerationOptions
{
    public function __construct(
        public ?float $temperature = null,
        public ?int $maxTokens = null,
        public ?int $maxSteps = null,
        public ?float $topP = null,
    ) {}

    /**
     * Push the options onto the agent. Returns the agent for fluent chaining.
     */
    public function applyTo(SolarisAgent $agent): SolarisAgent
    {
        return $agent
            ->withTemperature($this->temperature)
            ->withMaxTokens($this->maxTokens)
            ->withMaxSteps($this->maxSteps)
            ->withTopP($this->topP);
    }
}
