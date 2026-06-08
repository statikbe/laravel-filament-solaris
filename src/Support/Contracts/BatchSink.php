<?php

namespace Statikbe\FilamentSolaris\Support\Contracts;

use Statikbe\FilamentSolaris\Support\BatchOutcome;

/**
 * Receives one BatchOutcome per processed batch. Implementations decide where
 * outcomes go — accumulate in memory (sync) or persist to a store (queued).
 */
interface BatchSink
{
    public function record(BatchOutcome $outcome): void;
}
