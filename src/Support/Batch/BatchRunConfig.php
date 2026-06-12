<?php

namespace Statikbe\FilamentSolaris\Support\Batch;

use Laravel\Ai\Enums\Lab;

/**
 * Pure-scalar snapshot of an AiGenerateAction's records-loop configuration —
 * everything a worker needs to rebuild the agent + schema + write-back WITHOUT
 * any closure or live model. Serializes cleanly into ProcessChunkJob.
 */
final readonly class BatchRunConfig
{
    /**
     * @param  array<int, string>  $onlyColumns
     * @param  array<int, string>  $exceptColumns
     * @param  array<string, string>  $columnHints
     * @param  array<string, array<int, string>>  $columnEnums
     * @param  Lab|array<mixed>|string|null  $provider
     */
    public function __construct(
        public string $actionName,
        public ?string $modelClass,
        public array $onlyColumns,
        public array $exceptColumns,
        public array $columnHints,
        public array $columnEnums,
        public string $identifierKey,
        public ?string $writeTerminal,
        public Lab|array|string|null $provider,
        public ?string $model,
        public ?int $timeout,
        public string $runId,
        public ?float $temperature,
        public ?int $maxTokens,
        public ?int $maxSteps,
        public ?float $topP,
    ) {}
}
