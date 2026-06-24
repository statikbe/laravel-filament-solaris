<?php

namespace Statikbe\FilamentSolaris\Generation;

use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Result of a single structured {@see AiGenerator::runInline()} call.
 *
 * `data` is the parsed structured output (the model's JSON object as a PHP
 * array). `usage` and `conversationId` may be null depending on the provider/
 * response. `response` is the raw laravel/ai response for advanced callers.
 */
final readonly class GenerationResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
        public ?Usage $usage,
        public ?string $conversationId,
        public StructuredAgentResponse $response,
    ) {}
}
