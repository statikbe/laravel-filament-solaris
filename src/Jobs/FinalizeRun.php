<?php

namespace Statikbe\FilamentSolaris\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Events\SolarisBatchCompleted;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;

/**
 * Bus ->finally hook: read the persisted run aggregate, mark it completed, fire
 * SolarisBatchCompleted. The configurable completion handler + notification +
 * report are later pieces (#4 / #6); the built-in notification is wired in a
 * later task of this same piece.
 */
class FinalizeRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $runId,
        public string $actionName,
        public bool $cancelled = false,
    ) {}

    public function handle(): void
    {
        $run = SolarisBatchRun::find($this->runId);

        if ($run === null) {
            return;
        }

        $status = $this->cancelled ? BatchRunStatus::Failed : BatchRunStatus::Completed;
        $run->markCompleted($status);

        SolarisBatchCompleted::dispatch(
            $run->id,
            $run->action_name,
            $run->succeeded,
            $run->failed,
            $run->discarded,
            $status,
        );
    }
}
