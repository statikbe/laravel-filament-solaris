<?php

use Statikbe\FilamentSolaris\Support\BatchOutcome;
use Statikbe\FilamentSolaris\Support\DiscardedOutput;
use Statikbe\FilamentSolaris\Support\FailedRecord;

it('defaults discarded to an empty array (back-compatible 2-arg construction)', function () {
    $outcome = new BatchOutcome(2, [new FailedRecord(1, 'x')]);

    expect($outcome->succeeded)->toBe(2)
        ->and($outcome->failures)->toHaveCount(1)
        ->and($outcome->discarded)->toBe([]);
});

it('carries discarded outputs when provided', function () {
    $discard = new DiscardedOutput('hallucinated', ['_index' => 99], 'spurious');
    $outcome = new BatchOutcome(0, [], [$discard]);

    expect($outcome->discarded)->toHaveCount(1)
        ->and($outcome->discarded[0]->kind)->toBe('hallucinated')
        ->and($outcome->discarded[0]->record)->toBe(['_index' => 99])
        ->and($outcome->discarded[0]->reason)->toBe('spurious');
});
