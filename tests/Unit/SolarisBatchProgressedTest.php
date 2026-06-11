<?php

use Statikbe\FilamentSolaris\Events\SolarisBatchProgressed;

it('carries per-chunk progress counts', function () {
    $e = new SolarisBatchProgressed('run-1', 'importCategories', 8, 2, 1);

    expect($e->runId)->toBe('run-1')
        ->and($e->actionName)->toBe('importCategories')
        ->and($e->succeeded)->toBe(8)
        ->and($e->failed)->toBe(2)
        ->and($e->discarded)->toBe(1);
});
