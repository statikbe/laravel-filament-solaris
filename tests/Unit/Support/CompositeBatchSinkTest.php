<?php

use Statikbe\FilamentSolaris\Support\Batch\BatchOutcome;
use Statikbe\FilamentSolaris\Support\Batch\Sinks\CompositeBatchSink;
use Statikbe\FilamentSolaris\Support\Batch\Sinks\InMemoryBatchSink;

it('fans each outcome out to every sink in order', function () {
    $a = new InMemoryBatchSink;
    $b = new InMemoryBatchSink;
    (new CompositeBatchSink([$a, $b]))->record(new BatchOutcome(3, [], []));

    expect($a->succeeded())->toBe(3)->and($b->succeeded())->toBe(3);
});
