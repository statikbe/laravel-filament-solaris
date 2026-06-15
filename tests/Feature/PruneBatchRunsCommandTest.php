<?php

use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Models\SolarisBatchProblem;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;

beforeEach(function () {
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $file) {
        $migration = include $file;
        $migration->down();
        $migration->up();
    }
});

afterEach(function () {
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $file) {
        (include $file)->down();
    }
});

function makeRun(BatchRunStatus $status, ?string $finishedAt): SolarisBatchRun
{
    return SolarisBatchRun::create([
        'action_name' => 'x',
        'status' => $status,
        'finished_at' => $finishedAt,
    ]);
}

it('prunes terminal runs older than the window and cascades their problems', function () {
    $old = makeRun(BatchRunStatus::Completed, now()->subDays(40)->toDateTimeString());
    SolarisBatchProblem::create(['batch_run_id' => $old->id, 'type' => 'failure', 'reason' => 'boom']);
    $recent = makeRun(BatchRunStatus::Completed, now()->subDays(5)->toDateTimeString());
    $running = makeRun(BatchRunStatus::Processing, null);

    $this->artisan('solaris:prune-batches', ['--days' => 30, '--force' => true])
        ->assertSuccessful();

    expect(SolarisBatchRun::whereKey($old->id)->exists())->toBeFalse()
        ->and(SolarisBatchProblem::count())->toBe(0)
        ->and(SolarisBatchRun::whereKey($recent->id)->exists())->toBeTrue()
        ->and(SolarisBatchRun::whereKey($running->id)->exists())->toBeTrue();
});

it('fails without a retention window and deletes nothing', function () {
    makeRun(BatchRunStatus::Completed, now()->subDays(40)->toDateTimeString());

    $this->artisan('solaris:prune-batches', ['--force' => true])
        ->assertFailed();

    expect(SolarisBatchRun::count())->toBe(1);
});

it('uses the configured prune_after_days when --days is omitted', function () {
    config()->set('filament-solaris.batch_tracking.prune_after_days', 30);
    $old = makeRun(BatchRunStatus::Completed, now()->subDays(40)->toDateTimeString());

    $this->artisan('solaris:prune-batches', ['--force' => true])->assertSuccessful();

    expect(SolarisBatchRun::whereKey($old->id)->exists())->toBeFalse();
});

it('prunes across multiple chunks', function () {
    config()->set('filament-solaris.batch_tracking.prune_chunk', 5);
    foreach (range(1, 12) as $i) {
        makeRun(BatchRunStatus::Failed, now()->subDays(40)->toDateTimeString());
    }

    $this->artisan('solaris:prune-batches', ['--days' => 30, '--force' => true])->assertSuccessful();

    expect(SolarisBatchRun::count())->toBe(0);
});
