<?php

namespace Statikbe\FilamentSolaris\Support;

/**
 * Parsed structured-output response from one batched AI call.
 * Universal payload shape for AiGenerateAction in forModel mode.
 */
final readonly class BatchResponse
{
    /** Top-level payload keys — the single source of truth for the batched shape. */
    public const RECORDS = 'records';

    public const FAILED = 'failed';

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @param  array<int, FailedRecord>  $failed
     */
    public function __construct(
        public array $records,
        public array $failed,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $rawFailed = $payload[self::FAILED] ?? [];

        return new self(
            records: $payload[self::RECORDS] ?? [],
            failed: array_map(
                fn (array $row): FailedRecord => new FailedRecord(
                    identifier: $row['identifier'] ?? null,
                    reason: (string) ($row['reason'] ?? ''),
                ),
                $rawFailed,
            ),
        );
    }
}
