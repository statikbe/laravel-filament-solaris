<?php

namespace Statikbe\FilamentSolaris\Support\Batch\Runners;

use Closure;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Statikbe\FilamentSolaris\Jobs\FinalizeRun;
use Statikbe\FilamentSolaris\Jobs\ProcessChunkJob;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;
use Statikbe\FilamentSolaris\Support\Batch\BatchRunConfig;

/**
 * Transport layer for a queued records-loop run: turn pre-chunked rows into a
 * Bus::batch of ProcessChunkJob, with FinalizeRun as the ->finally hook. Knows
 * nothing about prompt rendering or descriptor shape — those arrive as closures
 * the action supplies (it owns that domain logic + its protected helpers).
 */
final class QueuedRunner
{
    /**
     * @param  iterable<int, array<int, mixed>>  $chunks
     * @param  Closure(array<int, mixed>): string  $renderPrompt
     * @param  Closure(array<int, mixed>): array<int, array<string, mixed>>  $buildDescriptors
     */
    public function dispatch(
        SolarisBatchRun $run,
        BatchRunConfig $config,
        iterable $chunks,
        Closure $renderPrompt,
        Closure $buildDescriptors,
    ): void {
        $jobs = [];

        foreach ($chunks as $chunk) {
            $jobs[] = new ProcessChunkJob(
                config: $config,
                prompt: $renderPrompt($chunk),
                rowDescriptors: array_values($buildDescriptors($chunk)),
            );
        }

        $runId = $run->id;
        $actionName = $config->actionName;

        Bus::batch($jobs)
            ->name('solaris:'.$runId)
            ->allowFailures()
            ->finally(function (Batch $batch) use ($runId, $actionName) {
                FinalizeRun::dispatch($runId, $actionName, $batch->cancelled());
            })
            ->dispatch();
    }
}
