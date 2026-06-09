<?php

namespace Statikbe\FilamentSolaris\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when a tracked AiGenerateAction records-loop run begins. Carries ids
 * and counts only — no row data (PII-conscious, like SolarisResponseReceived).
 */
final class SolarisBatchStarted
{
    use Dispatchable;

    public function __construct(
        public readonly string $runId,
        public readonly string $actionName,
        public readonly ?string $userId,
        public readonly ?string $page,
        public readonly ?int $total,
    ) {}
}
