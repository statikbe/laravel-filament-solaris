<?php

namespace Statikbe\FilamentSolaris\Support\Batch;

use Statikbe\FilamentSolaris\Support\Batch\Handlers\NotifyOnBatchCompletion;

/**
 * Runs a resolved list of BatchCompletionHandler class-strings against a summary,
 * in order. A throwing handler is report()-ed and does not abort the rest — a
 * completion run has already happened; one bad handler must not swallow the others.
 */
final class CompletionHandlerRunner
{
    /**
     * Apply the framework default when no explicit list is given. Single source of
     * truth for "which handlers run", shared by the inline and queued paths.
     *
     * @param  array<int, class-string>|null  $handlers
     * @return array<int, class-string>
     */
    public static function resolve(?array $handlers): array
    {
        return $handlers
            ?? config('filament-solaris.batch_tracking.completion_handlers', [NotifyOnBatchCompletion::class]);
    }

    /**
     * @param  array<int, class-string<BatchCompletionHandler>>  $handlers
     */
    public function run(array $handlers, BatchSummary $summary): void
    {
        foreach ($handlers as $handler) {
            try {
                app($handler)->handle($summary);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
