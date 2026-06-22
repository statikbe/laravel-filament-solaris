<?php

namespace Statikbe\FilamentSolaris\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;

/**
 * Dispatched when a tracked AiGenerateAction records-loop run finishes.
 * Broadcast-ready: emits on a public per-run channel when broadcasting is
 * enabled. Opt-in/auto via config — broadcastWhen().
 */
final class SolarisBatchCompleted implements ShouldBroadcast
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

    public function broadcastOn(): Channel
    {
        return new Channel('solaris.batch.'.$this->runId);
    }

    public function broadcastAs(): string
    {
        return 'solaris.batch.completed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'runId' => $this->runId,
            'actionName' => $this->actionName,
            'succeeded' => $this->succeeded,
            'failed' => $this->failed,
            'discarded' => $this->discarded,
            'status' => $this->status->value,
        ];
    }

    public function broadcastWhen(): bool
    {
        $flag = config('filament-solaris.batch_tracking.live_updates.broadcast');

        return $flag ?? (config('broadcasting.default') !== 'null');
    }
}
