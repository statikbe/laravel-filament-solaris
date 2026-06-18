<?php

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;
use Statikbe\FilamentSolaris\Support\Batch\BatchFailureReport;
use Statikbe\FilamentSolaris\Support\Batch\BatchReportFormat;
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
    Log::spy();

    app(NotifyOnBatchCompletion::class)->handle(
        new BatchSummary('act', 'run-x', 1, 0, 0, BatchRunStatus::Completed, queued: true),
    );

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'run-x'))
        ->once();
});

it('does nothing when notify_on_completion is disabled', function () {
    config()->set('filament-solaris.batch_tracking.notify_on_completion', false);

    app(NotifyOnBatchCompletion::class)->handle(
        new BatchSummary('act', null, 1, 0, 0, BatchRunStatus::Completed, queued: false),
    );

    Notification::assertNotNotified();
});

it('attaches CSV + XLSX download actions to a queued completion with failures', function () {
    $user = NotifiableUser::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'x']);
    $run = SolarisBatchRun::create([
        'action_name' => 'x', 'user_id' => (string) $user->getKey(),
        'status' => BatchRunStatus::Completed, 'succeeded' => 2, 'failed' => 1,
    ]);

    app(NotifyOnBatchCompletion::class)->handle(
        new BatchSummary('x', $run->id, 2, 1, 0, BatchRunStatus::Completed, queued: true),
    );

    $data = $user->fresh()->notifications()->first()->data;
    $urls = collect($data['actions'] ?? [])->pluck('url')->filter()->values();
    expect($urls)->toContain(BatchFailureReport::downloadUrl($run->id, BatchReportFormat::Csv))
        ->and($urls)->toContain(BatchFailureReport::downloadUrl($run->id, BatchReportFormat::Xlsx));
});

it('omits download actions when there are no failures', function () {
    $user = NotifiableUser::create(['name' => 'B', 'email' => 'b@x.test', 'password' => 'x']);
    $run = SolarisBatchRun::create(['action_name' => 'x', 'user_id' => (string) $user->getKey(), 'status' => BatchRunStatus::Completed, 'succeeded' => 3, 'failed' => 0]);

    app(NotifyOnBatchCompletion::class)->handle(
        new BatchSummary('x', $run->id, 3, 0, 0, BatchRunStatus::Completed, queued: true),
    );

    $data = $user->fresh()->notifications()->first()->data;
    expect(collect($data['actions'] ?? [])->pluck('url')->filter())->toBeEmpty();
});

it('omits download actions when attach_failure_report is off', function () {
    config()->set('filament-solaris.batch_tracking.attach_failure_report', false);
    $user = NotifiableUser::create(['name' => 'C', 'email' => 'c@x.test', 'password' => 'x']);
    $run = SolarisBatchRun::create(['action_name' => 'x', 'user_id' => (string) $user->getKey(), 'status' => BatchRunStatus::Failed, 'succeeded' => 0, 'failed' => 2]);

    app(NotifyOnBatchCompletion::class)->handle(
        new BatchSummary('x', $run->id, 0, 2, 0, BatchRunStatus::Failed, queued: true),
    );

    $data = $user->fresh()->notifications()->first()->data;
    expect(collect($data['actions'] ?? [])->pluck('url')->filter())->toBeEmpty();
});

it('a tracked inline run both persists to the bell and flashes a toast', function () {
    $user = NotifiableUser::create(['name' => 'D', 'email' => 'd@x.test', 'password' => 'x']);
    $run = SolarisBatchRun::create([
        'action_name' => 'x', 'user_id' => (string) $user->getKey(),
        'status' => BatchRunStatus::Completed, 'succeeded' => 1, 'failed' => 0,
    ]);

    app(NotifyOnBatchCompletion::class)->handle(
        new BatchSummary('x', $run->id, 1, 0, 0, BatchRunStatus::Completed, queued: false),
    );

    Notification::assertNotified();                          // flashed for immediacy
    expect($user->fresh()->notifications()->count())->toBe(1); // and persisted to the bell
});

it('honors the per-action attach_failure_report override from run meta', function () {
    // config ON, but the run opted OUT via ->withFailureReport(false) (stashed in meta) → no actions
    config()->set('filament-solaris.batch_tracking.attach_failure_report', true);
    $user = NotifiableUser::create(['name' => 'E', 'email' => 'e@x.test', 'password' => 'x']);
    $run = SolarisBatchRun::create([
        'action_name' => 'x', 'user_id' => (string) $user->getKey(),
        'status' => BatchRunStatus::Completed, 'succeeded' => 1, 'failed' => 1,
        'meta' => ['attach_failure_report' => false],
    ]);

    app(NotifyOnBatchCompletion::class)->handle(
        new BatchSummary('x', $run->id, 1, 1, 0, BatchRunStatus::Completed, queued: true),
    );

    $data = $user->fresh()->notifications()->first()->data;
    expect(collect($data['actions'] ?? [])->pluck('url')->filter())->toBeEmpty();
});

it('attaches the report when the run opted in via meta even with config off', function () {
    config()->set('filament-solaris.batch_tracking.attach_failure_report', false);
    $user = NotifiableUser::create(['name' => 'F', 'email' => 'f@x.test', 'password' => 'x']);
    $run = SolarisBatchRun::create([
        'action_name' => 'x', 'user_id' => (string) $user->getKey(),
        'status' => BatchRunStatus::Completed, 'succeeded' => 1, 'failed' => 1,
        'meta' => ['attach_failure_report' => true],
    ]);

    app(NotifyOnBatchCompletion::class)->handle(
        new BatchSummary('x', $run->id, 1, 1, 0, BatchRunStatus::Completed, queued: true),
    );

    $data = $user->fresh()->notifications()->first()->data;
    expect(collect($data['actions'] ?? [])->pluck('url')->filter())->not->toBeEmpty();
});
