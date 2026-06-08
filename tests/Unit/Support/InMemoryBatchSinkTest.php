<?php

use Statikbe\FilamentSolaris\Support\Batch\BatchOutcome;
use Statikbe\FilamentSolaris\Support\Batch\DiscardedOutput;
use Statikbe\FilamentSolaris\Support\Batch\FailedRecord;
use Statikbe\FilamentSolaris\Support\Batch\Sinks\InMemoryBatchSink;

it('accumulates succeeded, failures and discarded across batches', function () {
    $sink = new InMemoryBatchSink;

    $sink->record(new BatchOutcome(2, [new FailedRecord(1, 'a')], []));
    $sink->record(new BatchOutcome(3, [], [new DiscardedOutput('duplicate', ['_index' => 0], 'dup')]));

    expect($sink->succeeded())->toBe(5)
        ->and($sink->failures())->toHaveCount(1)
        ->and($sink->discarded())->toHaveCount(1)
        ->and($sink->discarded()[0]->kind)->toBe('duplicate');
});
