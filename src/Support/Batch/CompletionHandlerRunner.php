<?php

namespace Statikbe\FilamentSolaris\Support\Batch;

/**
 * Runs a resolved list of BatchCompletionHandler class-strings against a summary,
 * in order. A throwing handler is report()-ed and does not abort the rest — a
 * completion run has already happened; one bad handler must not swallow the others.
 */
final class CompletionHandlerRunner
{
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
