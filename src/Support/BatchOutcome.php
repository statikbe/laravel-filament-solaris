<?php

namespace Statikbe\FilamentSolaris\Support;

/**
 * Result of processing one batch: how many writes succeeded and the
 * aggregated failure list (AI-reported failures + silent drops +
 * hallucinated identifiers + write errors).
 */
final readonly class BatchOutcome
{
    /**
     * @param  array<int, FailedRecord>  $failures
     */
    public function __construct(
        public int $succeeded,
        public array $failures,
    ) {}
}
