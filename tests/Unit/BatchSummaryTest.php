<?php

use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Support\Batch\BatchSummary;

it('exposes completion counts, status and path flag', function () {
    $s = new BatchSummary(
        actionName: 'importCategories',
        runId: 'run-1',
        succeeded: 8,
        failed: 2,
        discarded: 1,
        status: BatchRunStatus::Completed,
        queued: true,
        userInput: ['focus' => 'seo'],
    );

    expect($s->actionName)->toBe('importCategories')
        ->and($s->runId)->toBe('run-1')
        ->and($s->succeeded)->toBe(8)
        ->and($s->failed)->toBe(2)
        ->and($s->discarded)->toBe(1)
        ->and($s->status)->toBe(BatchRunStatus::Completed)
        ->and($s->queued)->toBeTrue()
        ->and($s->userInput)->toBe(['focus' => 'seo'])
        ->and($s->total())->toBe(10);
});
