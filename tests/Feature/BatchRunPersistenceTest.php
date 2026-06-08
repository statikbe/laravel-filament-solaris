<?php

use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Models\SolarisBatchProblem;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;

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
    $run = Statikbe\FilamentSolaris\Models\SolarisBatchRun::create(['action_name' => 'x', 'status' => Statikbe\FilamentSolaris\Enums\BatchRunStatus::Processing]);
    $sink = new Statikbe\FilamentSolaris\Support\DatabaseBatchSink($run->id);

    $sink->record(new Statikbe\FilamentSolaris\Support\BatchOutcome(
        2,
        [new Statikbe\FilamentSolaris\Support\FailedRecord(5, 'write error: boom', ['name' => 'E'])],
        [new Statikbe\FilamentSolaris\Support\DiscardedOutput('unmatched', ['_index' => 99, 'name' => 'ghost'], 'unmatched id 99')],
    ));
    $sink->record(new Statikbe\FilamentSolaris\Support\BatchOutcome(1, [], []));

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
