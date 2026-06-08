<?php

use Statikbe\FilamentSolaris\Support\BatchOutcome;
use Statikbe\FilamentSolaris\Support\CompositeBatchSink;
use Statikbe\FilamentSolaris\Support\InMemoryBatchSink;

it('fans each outcome out to every sink in order', function () {
    $a = new InMemoryBatchSink;
    $b = new InMemoryBatchSink;
    (new CompositeBatchSink([$a, $b]))->record(new BatchOutcome(3, [], []));

    expect($a->succeeded())->toBe(3)->and($b->succeeded())->toBe(3);
});
