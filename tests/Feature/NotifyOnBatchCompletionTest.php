<?php

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Schema;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;
use Statikbe\FilamentSolaris\Support\Batch\BatchSummary;
use Statikbe\FilamentSolaris\Support\Batch\Handlers\NotifyOnBatchCompletion;
use Statikbe\FilamentSolaris\Tests\Fixtures\NotifiableUser;

beforeEach(function () {
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $file) {
        $m = include $file;
        $m->down();
        $m->up();
    }
    // Testbench's maintained users + notifications tables (don't hand-roll them).
    foreach (glob(dirname(__DIR__, 2).'/vendor/orchestra/testbench-core/laravel/migrations/*_create_users_table.php') as $f) {
        if (! Schema::hasTable('users')) {
            (include $f)->up();
        }
    }
    foreach (glob(dirname(__DIR__, 2).'/vendor/orchestra/testbench-core/laravel/migrations/notifications/*_create_notifications_table.php') as $f) {
        if (! Schema::hasTable('notifications')) {
            (include $f)->up();
        }
    }
    config()->set('auth.providers.users.model', NotifiableUser::class);
    config()->set('filament-solaris.batch_tracking.notify_on_completion', true);
});

afterEach(function () {
    Schema::dropIfExists('notifications');
    Schema::dropIfExists('users');
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $file) {
        (include $file)->down();
    }
});

it('flashes a session toast on the inline path', function () {
    app(NotifyOnBatchCompletion::class)->handle(
        new BatchSummary('act', null, 3, 0, 0, BatchRunStatus::Completed, queued: false),
    );

    Notification::assertNotified();
});

it('sends a database notification to the run user on the queued path', function () {
    $user = NotifiableUser::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'x']);
    $run = SolarisBatchRun::create([
        'action_name' => 'act', 'user_id' => (string) $user->getKey(),
        'status' => BatchRunStatus::Completed, 'succeeded' => 2, 'failed' => 0,
    ]);

    app(NotifyOnBatchCompletion::class)->handle(
        new BatchSummary('act', $run->id, 2, 0, 0, BatchRunStatus::Completed, queued: true),
    );

    expect($user->fresh()->notifications()->count())->toBe(1);
});

it('logs instead of throwing when the queued user is unresolvable', function () {
    app(NotifyOnBatchCompletion::class)->handle(
        new BatchSummary('act', 'run-x', 1, 0, 0, BatchRunStatus::Completed, queued: true),
    );

    expect(true)->toBeTrue(); // no exception thrown
});

it('does nothing when notify_on_completion is disabled', function () {
    config()->set('filament-solaris.batch_tracking.notify_on_completion', false);

    app(NotifyOnBatchCompletion::class)->handle(
        new BatchSummary('act', null, 1, 0, 0, BatchRunStatus::Completed, queued: false),
    );

    Notification::assertNotNotified();
});
