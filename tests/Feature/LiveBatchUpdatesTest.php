<?php

use Illuminate\Support\Facades\Schema;
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;
use Statikbe\FilamentSolaris\Tests\Fixtures\NotifiableUser;
use Statikbe\FilamentSolaris\Tests\Fixtures\SeedCategory;

beforeEach(function () {
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $file) {
        $migration = include $file;
        $migration->down();   // drop any table leaked by an earlier test (idempotent)
        $migration->up();
    }
    Schema::dropIfExists('seed_categories');
    Schema::create('seed_categories', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('slug');
        $table->timestamps();
    });

    // Testbench's maintained users table (don't hand-roll it) — needed for actingAs().
    foreach (glob(dirname(__DIR__, 2).'/vendor/orchestra/testbench-core/laravel/migrations/*_create_users_table.php') as $f) {
        if (! Schema::hasTable('users')) {
            (include $f)->up();
        }
    }
    config()->set('auth.providers.users.model', NotifiableUser::class);
});

afterEach(function () {
    Schema::dropIfExists('users');
    Schema::dropIfExists('seed_categories');
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $file) {
        (include $file)->down();
    }
});

function liveAction(): AiGenerateAction
{
    return AiGenerateAction::make('liveImport')
        ->forModel(SeedCategory::class)
        ->prompt('x')
        ->sourceRecords([['name' => 'A', 'slug' => 'a']])
        ->queued()
        ->liveBatchUpdates()
        ->createRecords();
}

function processingRun(string $action, ?string $userId, int $total = 10, int $succeeded = 4, int $failed = 0): SolarisBatchRun
{
    return SolarisBatchRun::create([
        'action_name' => $action, 'user_id' => $userId,
        'status' => BatchRunStatus::Processing, 'total' => $total, 'succeeded' => $succeeded, 'failed' => $failed,
        'started_at' => now(),
    ]);
}

it('resolves the active run for this action + current user', function () {
    $user = NotifiableUser::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'x']);
    $this->actingAs($user);
    processingRun('liveImport', (string) $user->getKey());

    $active = (new ReflectionMethod(AiGenerateAction::class, 'activeLiveRun'))->invoke(liveAction());
    expect($active)->not->toBeNull()->and($active->action_name)->toBe('liveImport');
});

it('ignores other actions / users / terminal runs', function () {
    $user = NotifiableUser::create(['name' => 'B', 'email' => 'b@x.test', 'password' => 'x']);
    $this->actingAs($user);
    processingRun('otherAction', (string) $user->getKey());
    processingRun('liveImport', 'someone-else');
    SolarisBatchRun::create(['action_name' => 'liveImport', 'user_id' => (string) $user->getKey(), 'status' => BatchRunStatus::Completed]);

    expect((new ReflectionMethod(AiGenerateAction::class, 'activeLiveRun'))->invoke(liveAction()))->toBeNull();
});

it('disables + tooltips + wire:polls while a run is active', function () {
    $user = NotifiableUser::create(['name' => 'C', 'email' => 'c@x.test', 'password' => 'x']);
    $this->actingAs($user);
    processingRun('liveImport', (string) $user->getKey(), total: 10, succeeded: 4, failed: 1);

    $action = liveAction();
    expect($action->isDisabled())->toBeTrue()
        ->and($action->getTooltip())->toContain('5')                 // done = 4+1
        ->and($action->getExtraAttributes())->toHaveKey('wire:poll.3s');
});

it('is enabled with no poll attr when idle', function () {
    $user = NotifiableUser::create(['name' => 'D', 'email' => 'd@x.test', 'password' => 'x']);
    $this->actingAs($user);

    $action = liveAction();
    expect($action->isDisabled())->toBeFalse()
        ->and($action->getExtraAttributes())->not->toHaveKey('wire:poll.3s');
});
