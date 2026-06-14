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
 * Transport layer for a queued run: turn the action's pre-built plan into a
 * Bus::batch of ProcessChunkJob, with FinalizeRun as the ->finally hook. Owns all
 * Bus mechanics; knows nothing about prompt rendering or descriptor shape — those
 * arrive as closures (chunked) or a ready string (single-call) the action supplies.
 */
final class QueuedRunner
{
    /**
     * Chunked records-loop run: one job per chunk, prompts + descriptors rendered
     * lazily via the supplied closures.
     *
     * @param  iterable<int, array<int, mixed>>  $chunks
     * @param  Closure(array<int, mixed>): string  $renderPrompt
     * @param  Closure(array<int, mixed>): array<int, array<string, mixed>>  $buildDescriptors
     * @param  array<int, array<string, mixed>>  $attachments
     */
    public function dispatch(
        SolarisBatchRun $run,
        BatchRunConfig $config,
        iterable $chunks,
        Closure $renderPrompt,
        Closure $buildDescriptors,
        array $attachments = [],
    ): void {
        $jobs = [];

        foreach ($chunks as $chunk) {
            $jobs[] = new ProcessChunkJob(
                config: $config,
                prompt: $renderPrompt($chunk),
                rowDescriptors: array_values($buildDescriptors($chunk)),
                attachments: $attachments,
            );
        }

        $this->dispatchBatch($run, $config, $jobs);
    }

    /**
     * Single-call / from-scratch run: exactly one job, no descriptors, a ready
     * prompt string (e.g. "extract products from the attached PDF").
     *
     * @param  array<int, array<string, mixed>>  $attachments
     */
    public function dispatchSingleCall(
        SolarisBatchRun $run,
        BatchRunConfig $config,
        string $prompt,
        array $attachments = [],
    ): void {
        $this->dispatchBatch($run, $config, [
            new ProcessChunkJob(config: $config, prompt: $prompt, rowDescriptors: [], attachments: $attachments),
        ]);
    }

    /**
     * @param  array<int, ProcessChunkJob>  $jobs
     */
    private function dispatchBatch(SolarisBatchRun $run, BatchRunConfig $config, array $jobs): void
    {
        $runId = $run->id;
        $actionName = $config->actionName;

        Bus::batch($jobs)
            ->name('solaris:'.$runId)
            ->allowFailures()
            ->finally(function (Batch $batch) use ($runId, $actionName) {
                FinalizeRun::dispatch($runId, $actionName, $batch->cancelled(), $batch->hasFailures());
            })
            ->dispatch();
    }
}
