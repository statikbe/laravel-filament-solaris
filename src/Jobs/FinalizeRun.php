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
use Statikbe\FilamentSolaris\Support\Batch\BatchSummary;
use Statikbe\FilamentSolaris\Support\Batch\CompletionHandlerRunner;

/**
 * Bus ->finally hook: mark the run done (Failed on cancel or job-level failure),
 * fire SolarisBatchCompleted (the event substrate), then run the run's completion
 * handlers (resolved from run.meta) against a queued BatchSummary.
 */
class FinalizeRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $runId,
        public string $actionName,
        public bool $cancelled = false,
        public bool $hasFailures = false,
    ) {}

    public function handle(): void
    {
        $run = SolarisBatchRun::find($this->runId);

        if ($run === null) {
            return;
        }

        $status = ($this->cancelled || $this->hasFailures) ? BatchRunStatus::Failed : BatchRunStatus::Completed;
        $run->markCompleted($status);

        SolarisBatchCompleted::dispatch($run->id, $run->action_name, $run->succeeded, $run->failed, $run->discarded, $status);

        $summary = new BatchSummary(
            actionName: $run->action_name,
            runId: $run->id,
            succeeded: $run->succeeded,
            failed: $run->failed,
            discarded: $run->discarded,
            status: $status,
            queued: true,
            userInput: $run->meta['userInput'] ?? [],
        );

        $handlers = CompletionHandlerRunner::resolve($run->meta['completionHandlers'] ?? null);

        (new CompletionHandlerRunner)->run($handlers, $summary);
    }
}
