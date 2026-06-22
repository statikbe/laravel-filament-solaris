<?php

namespace Statikbe\FilamentSolaris\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by ProcessChunkJob after each chunk persists. Broadcast-ready: emits on a
 * public per-run channel (counts only, no PII) when broadcasting is enabled, so
 * live-update UIs can refresh without polling. Opt-in/auto via config — broadcastWhen().
 */
final class SolarisBatchProgressed implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public readonly string $runId,
        public readonly string $actionName,
        public readonly int $succeeded,
        public readonly int $failed,
        public readonly int $discarded,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('solaris.batch.'.$this->runId);
    }

    public function broadcastAs(): string
    {
        return 'solaris.batch.progressed';
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
        ];
    }

    public function broadcastWhen(): bool
    {
        $flag = config('filament-solaris.batch_tracking.live_updates.broadcast');

        return $flag ?? (config('broadcasting.default') !== 'null');
    }
}
