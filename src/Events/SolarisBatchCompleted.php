<?php

namespace Statikbe\FilamentSolaris\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;

/**
 * Dispatched when a tracked AiGenerateAction records-loop run finishes.
 */
final class SolarisBatchCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $runId,
        public readonly string $actionName,
        public readonly int $succeeded,
        public readonly int $failed,
        public readonly int $discarded,
        public readonly BatchRunStatus $status,
    ) {}
}
