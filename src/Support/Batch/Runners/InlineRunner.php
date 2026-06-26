<?php

namespace Statikbe\FilamentSolaris\Support\Batch\Runners;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Events\SolarisBatchCompleted;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;
use Statikbe\FilamentSolaris\Support\Batch\BatchProcessor;
use Statikbe\FilamentSolaris\Support\Batch\BatchResponse;
use Statikbe\FilamentSolaris\Support\Batch\BatchSummary;
use Statikbe\FilamentSolaris\Support\Batch\CompletionHandlerRunner;
use Statikbe\FilamentSolaris\Support\Batch\FailedRecord;
use Statikbe\FilamentSolaris\Support\Batch\Sinks\CompositeBatchSink;
use Statikbe\FilamentSolaris\Support\Batch\Sinks\DatabaseBatchSink;
use Statikbe\FilamentSolaris\Support\Batch\Sinks\InMemoryBatchSink;

/**
 * Runs the records loop in-process and returns a {@see BatchSummary}: drive the
 * {@see BatchProcessor} into an in-memory collector (plus a DatabaseBatchSink for
 * a tracked run), log discarded output, complete + announce a tracked run, then
 * finalize (failure manifest + completion handlers).
 *
 * The inline sibling of {@see QueuedRunner}: the caller resolves config to plain
 * values and the per-batch AI-call + write-back closures; this owns the lifecycle.
 */
final class InlineRunner
{
    /**
     * @param  iterable<int, array<string, mixed>|Model>  $rows
     * @param  Closure(array<int, array<string, mixed>|Model>): BatchResponse  $generateResponse
     * @param  Closure(mixed, array<string, mixed>): void  $persistRecord
     * @param  array<int, class-string>  $completionHandlers
     * @param  array<string, mixed>  $userInput
     */
    public function run(
        string $actionName,
        iterable $rows,
        int $batchSize,
        string $identifierKey,
        Closure $generateResponse,
        Closure $persistRecord,
        ?SolarisBatchRun $run,
        array $completionHandlers,
        array $userInput,
    ): BatchSummary {
        $collector = new InMemoryBatchSink;
        $sink = $run === null
            ? $collector
            : new CompositeBatchSink([$collector, new DatabaseBatchSink($run->id)]);

        $processor = new BatchProcessor($identifierKey, $generateResponse, $persistRecord, $sink);
        $processor->process($rows, $batchSize);

        foreach ($collector->discarded() as $drop) {
            $this->logToFailureChannel($drop->reason);
        }

        $succeeded = $collector->succeeded();
        $failures = $collector->failures();
        $discarded = count($collector->discarded());

        // Mirror the queued FinalizeRun ordering: complete the run + fire the event
        // (the substrate) before running completion handlers (the strategy), so a
        // tracked run's summary reflects the final Completed status.
        if ($run !== null) {
            $run->markCompleted();
            SolarisBatchCompleted::dispatch(
                $run->id,
                $actionName,
                $succeeded,
                count($failures),
                $discarded,
                BatchRunStatus::Completed,
            );
        }

        return $this->finalize($actionName, $succeeded, $failures, $discarded, $userInput, $run, $completionHandlers);
    }

    /**
     * Close out a run: log any failures, then run the completion handlers against
     * a path-agnostic summary. Shared with the single-call (no-processor) path.
     *
     * @param  array<int, FailedRecord>  $failures
     * @param  array<string, mixed>  $userInput
     * @param  array<int, class-string>  $completionHandlers
     */
    public function finalize(
        string $actionName,
        int $succeeded,
        array $failures,
        int $discarded,
        array $userInput,
        ?SolarisBatchRun $run,
        array $completionHandlers,
    ): BatchSummary {
        if ($failures !== []) {
            $this->reportFailures($actionName, $failures);
        }

        $summary = new BatchSummary(
            actionName: $actionName,
            runId: $run?->id,
            succeeded: $succeeded,
            failed: count($failures),
            discarded: $discarded,
            status: $run === null ? BatchRunStatus::Completed : $run->status,
            queued: false,
            userInput: $userInput,
        );

        (new CompletionHandlerRunner)->run($completionHandlers, $summary);

        return $summary;
    }

    /**
     * Log the failure manifest so failures are never silently dropped, regardless
     * of which completion handlers are registered. Models are reduced to their
     * key to keep the log readable.
     *
     * @param  array<int, FailedRecord>  $failures
     */
    private function reportFailures(string $actionName, array $failures): void
    {
        $this->logToFailureChannel(
            'AiGenerateAction: '.count($failures).' record(s) failed during a batched run.',
            [
                'action' => $actionName,
                'failures' => array_map(fn (FailedRecord $f): array => [
                    'identifier' => $f->identifier,
                    'reason' => $f->reason,
                    'input' => $f->input instanceof Model ? $f->input->getKey() : $f->input,
                ], $failures),
            ],
        );
    }

    /**
     * Log a batch diagnostic on the failure-logging channel (gated by
     * `failure_logging.enabled`). Used for the aggregated manifest and for
     * reconcile anomalies (unmatched / duplicate identifiers) — none of which
     * are bugs, so they go here rather than to `report()`.
     *
     * @param  array<string, mixed>  $context
     */
    private function logToFailureChannel(string $message, array $context = []): void
    {
        $config = FilamentSolaris::config();

        if (! $config->isFailureLoggingEnabled()) {
            return;
        }

        $channel = $config->getFailureLoggingChannel();

        if ($channel !== null) {
            Log::channel($channel)->warning($message, $context);

            return;
        }

        Log::warning($message, $context);
    }
}
