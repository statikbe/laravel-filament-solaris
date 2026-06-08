<?php

use Statikbe\FilamentSolaris\Support\Batch\BatchResponse;
use Statikbe\FilamentSolaris\Support\Batch\FailedRecord;

it('parses a well-formed payload into records and failed entries', function () {
    $payload = [
        'records' => [
            ['id' => 1, 'name' => 'A'],
            ['id' => 2, 'name' => 'B'],
        ],
        'failed' => [
            ['identifier' => 3, 'reason' => 'ambiguous source'],
        ],
    ];

    $response = BatchResponse::fromArray($payload);

    expect($response->records)->toHaveCount(2)
        ->and($response->records[0])->toBe(['id' => 1, 'name' => 'A'])
        ->and($response->failed)->toHaveCount(1)
        ->and($response->failed[0])->toBeInstanceOf(FailedRecord::class)
        ->and($response->failed[0]->identifier)->toBe(3)
        ->and($response->failed[0]->reason)->toBe('ambiguous source');
});

it('defaults missing top-level keys to empty arrays', function () {
    $response = BatchResponse::fromArray([]);

    expect($response->records)->toBe([])
        ->and($response->failed)->toBe([]);
});

it('coerces missing failure reason to empty string and missing identifier to null', function () {
    $response = BatchResponse::fromArray([
        'failed' => [['identifier' => 1], ['reason' => 'no identifier']],
    ]);

    expect($response->failed[0]->reason)->toBe('')
        ->and($response->failed[0]->identifier)->toBe(1)
        ->and($response->failed[1]->identifier)->toBeNull()
        ->and($response->failed[1]->reason)->toBe('no identifier');
});
