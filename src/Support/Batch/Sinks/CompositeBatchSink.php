<?php

namespace Statikbe\FilamentSolaris\Support\Batch\Sinks;

use Statikbe\FilamentSolaris\Support\Batch\BatchOutcome;
use Statikbe\FilamentSolaris\Support\Batch\BatchSink;

/**
 * Fans each BatchOutcome out to an ordered list of sinks — lets a run combine the
 * in-memory collector (drives the post-run callback/log) with persistence/events.
 */
final class CompositeBatchSink implements BatchSink
{
    /** @param  array<int, BatchSink>  $sinks */
    public function __construct(private array $sinks) {}

    public function record(BatchOutcome $outcome): void
    {
        foreach ($this->sinks as $sink) {
            $sink->record($outcome);
        }
    }
}
