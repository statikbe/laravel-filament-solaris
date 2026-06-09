<?php

use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Models\SolarisBatchProblem;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;
use Statikbe\FilamentSolaris\Support\Batch\BatchOutcome;
use Statikbe\FilamentSolaris\Support\Batch\DiscardedOutput;
use Statikbe\FilamentSolaris\Support\Batch\FailedRecord;
use Statikbe\FilamentSolaris\Support\Batch\Sinks\DatabaseBatchSink;

beforeEach(function () {
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $file) {
        (include $file)->up();
    }
});

afterEach(function () {
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $file) {
        (include $file)->down();
    }
});

it('auto-generates a uuid id and casts status + counts', function () {
    $run = SolarisBatchRun::create(['action_name' => 'import', 'status' => BatchRunStatus::Processing, 'total' => 5]);

    expect($run->id)->toBeString()->toHaveLength(36)
        ->and($run->status)->toBe(BatchRunStatus::Processing)
        ->and($run->succeeded)->toBe(0)
        ->and($run->failed)->toBe(0)
        ->and($run->discarded)->toBe(0);
});

it('marks a run completed with finished_at', function () {
    $run = SolarisBatchRun::create(['action_name' => 'x', 'status' => BatchRunStatus::Processing]);
    $run->markCompleted();

    expect($run->refresh()->status)->toBe(BatchRunStatus::Completed)
        ->and($run->finished_at)->not->toBeNull();
});

it('separates failure and discard problems via type scopes', function () {
    $run = SolarisBatchRun::create(['action_name' => 'x', 'status' => BatchRunStatus::Processing]);
    SolarisBatchProblem::create(['batch_run_id' => $run->id, 'type' => 'failure', 'identifier' => '2', 'reason' => 'bad', 'input' => ['name' => 'C']]);
    SolarisBatchProblem::create(['batch_run_id' => $run->id, 'type' => 'discard', 'identifier' => '99', 'reason' => 'unmatched', 'input' => ['_index' => 99]]);

    expect($run->problems()->count())->toBe(2)
        ->and($run->failures()->count())->toBe(1)
        ->and($run->discards()->count())->toBe(1)
        ->and($run->failures()->first()->input)->toBe(['name' => 'C']);
});

it('persists failures and discards as typed problem rows with atomic counts', function () {
    $run = SolarisBatchRun::create(['action_name' => 'x', 'status' => BatchRunStatus::Processing]);
    $sink = new DatabaseBatchSink($run->id);

    $sink->record(new BatchOutcome(
        2,
        [new FailedRecord(5, 'write error: boom', ['name' => 'E'])],
        [new DiscardedOutput('unmatched', ['_index' => 99, 'name' => 'ghost'], 'unmatched id 99')],
    ));
    $sink->record(new BatchOutcome(1, [], []));

    $run->refresh();
    expect($run->succeeded)->toBe(3)
        ->and($run->failed)->toBe(1)
        ->and($run->discarded)->toBe(1)
        ->and($run->failures()->count())->toBe(1)
        ->and($run->failures()->first()->identifier)->toBe('5')
        ->and($run->failures()->first()->input)->toBe(['name' => 'E'])
        ->and($run->discards()->count())->toBe(1)
        ->and($run->discards()->first()->identifier)->toBe('99')
        ->and($run->discards()->first()->input)->toBe(['_index' => 99, 'name' => 'ghost']);
});
