<?php

namespace Statikbe\FilamentSolaris\Support\Batch;

/**
 * Strategy invoked when a records-loop run finishes (inline or queued). The
 * framework default notifies; piece #6's failure report will be another handler.
 * Implementations are resolved from the container, so they may type-hint deps.
 */
interface BatchCompletionHandler
{
    public function handle(BatchSummary $summary): void;
}
