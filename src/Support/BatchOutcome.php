<?php

namespace Statikbe\FilamentSolaris\Support;

/**
 * Result of processing one batch: how many writes succeeded and the
 * aggregated failure list (AI-reported failures + silent drops +
 * unmatched identifiers + write errors).
 */
final readonly class BatchOutcome
{
    /**
     * @param  array<int, FailedRecord>  $failures
     * @param  array<int, DiscardedOutput>  $discarded
     */
    public function __construct(
        public int $succeeded,
        public array $failures,
        public array $discarded = [],
    ) {}
}
