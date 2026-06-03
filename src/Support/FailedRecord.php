<?php

namespace Statikbe\FilamentSolaris\Support;

/**
 * Represents a single record the AI could not process (or that failed at write).
 * Identifier shape depends on the action mode:
 *   - updateRecords: the model's primary key value
 *   - createRecords + sourceRecords: the injected _index (int)
 *   - single-call createRecords (no source): freeform string (line number, CSV row, etc.)
 *   - silent drops / hallucinated identifiers: null (or whatever the AI returned)
 */
final readonly class FailedRecord
{
    public function __construct(
        public mixed $identifier,
        public string $reason,
    ) {}
}
