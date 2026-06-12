<?php

namespace Statikbe\FilamentSolaris\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by ProcessChunkJob after each chunk's outcome is persisted, so live
 * updates (#5) can poll/subscribe for progress. Counts are this chunk's delta.
 */
final class SolarisBatchProgressed
{
    use Dispatchable;

    public function __construct(
        public readonly string $runId,
        public readonly string $actionName,
        public readonly int $succeeded,
        public readonly int $failed,
        public readonly int $discarded,
    ) {}
}
