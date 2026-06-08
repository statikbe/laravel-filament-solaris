<?php

namespace Statikbe\FilamentSolaris\Support;

/**
 * An AI output record the reconciler threw away because its identifier did not
 * map to exactly one un-consumed input row. Not a per-input-row failure — it is
 * spurious output. Surfaced on the BatchOutcome so the sink can log/persist it.
 */
final readonly class DiscardedOutput
{
    /**
     * @param  'unmatched'|'duplicate'  $kind
     * @param  array<string, mixed>  $record
     */
    public function __construct(
        public string $kind,
        public array $record,
        public string $reason,
    ) {}
}
