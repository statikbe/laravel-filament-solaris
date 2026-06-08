<?php

namespace Statikbe\FilamentSolaris\Support;

/**
 * Represents a single record the AI could not process (or that failed at write).
 * Identifier shape depends on the action mode:
 *   - updateRecords: the model's primary key value
 *   - createRecords + sourceRecords: the injected _index (int)
 *   - single-call createRecords (no source): freeform string (line number, CSV row, etc.)
 *   - silent drops / unmatched identifiers: null (or whatever the AI returned)
 *
 * $input carries the originating source row (array or Model) when it can be
 * recovered — whole-batch failures, silent drops, write errors, and AI-reported
 * failures whose identifier matches an input row. It is null for AI-reported
 * failures with no matching input (e.g. single-call createRecords parsing).
 */
final readonly class FailedRecord
{
    public function __construct(
        public mixed $identifier,
        public string $reason,
        public mixed $input = null,
    ) {}
}
