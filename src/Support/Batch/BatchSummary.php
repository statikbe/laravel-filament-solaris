<?php

namespace Statikbe\FilamentSolaris\Support\Batch;

use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;

/**
 * Path-agnostic, serializable snapshot of a finished records-loop run handed to
 * every BatchCompletionHandler. Carries COUNTS, not the failure rows — a handler
 * that wants failure detail queries solaris_batch_problems by runId (so it never
 * holds a large run's failures in memory; detail therefore needs a persisted run).
 */
final readonly class BatchSummary
{
    /**
     * @param  array<string, mixed>  $userInput
     */
    public function __construct(
        public string $actionName,
        public ?string $runId,
        public int $succeeded,
        public int $failed,
        public int $discarded,
        public BatchRunStatus $status,
        public bool $queued,
        public array $userInput = [],
    ) {}

    public function total(): int
    {
        return $this->succeeded + $this->failed;
    }

    /**
     * Fetch the persisted run row (null for an untracked inline run). A fresh query
     * per call — call once. Detail-only handlers should query solaris_batch_problems
     * by ->runId rather than loading the run just for that.
     */
    public function run(): ?SolarisBatchRun
    {
        return $this->runId === null ? null : SolarisBatchRun::find($this->runId);
    }
}
