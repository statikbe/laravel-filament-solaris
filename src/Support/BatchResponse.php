<?php

namespace Statikbe\FilamentSolaris\Support;

/**
 * Parsed structured-output response from one batched AI call.
 * Universal payload shape for AiGenerateAction in forModel mode.
 */
final readonly class BatchResponse
{
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
        $rawFailed = $payload['failed'] ?? [];

        return new self(
            records: $payload['records'] ?? [],
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
