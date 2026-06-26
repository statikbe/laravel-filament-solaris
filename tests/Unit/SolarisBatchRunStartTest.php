<?php

use Illuminate\Support\Facades\Event;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Events\SolarisBatchStarted;
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

it('creates a processing run with meta + started_at and fires SolarisBatchStarted', function () {
    Event::fake([SolarisBatchStarted::class]);

    $run = SolarisBatchRun::start(
        actionName: 'import',
        userId: '7',
        page: 'App\\Page',
        total: 5,
        userInput: ['locale' => 'fr'],
        completionHandlers: ['App\\Handler'],
        attachFailureReport: true,
    );

    expect($run->action_name)->toBe('import')
        ->and($run->user_id)->toBe('7')
        ->and($run->page)->toBe('App\\Page')
        ->and($run->status)->toBe(BatchRunStatus::Processing)
        ->and($run->total)->toBe(5)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->meta)->toBe([
            'userInput' => ['locale' => 'fr'],
            'completionHandlers' => ['App\\Handler'],
            'attach_failure_report' => true,
        ]);

    Event::assertDispatched(
        SolarisBatchStarted::class,
        fn ($e) => $e->runId === $run->id && $e->total === 5 && $e->actionName === 'import',
    );
});

it('accepts a null total and null user', function () {
    $run = SolarisBatchRun::start(
        actionName: 'seed',
        userId: null,
        page: null,
        total: null,
        userInput: [],
        completionHandlers: [],
        attachFailureReport: false,
    );

    expect($run->total)->toBeNull()
        ->and($run->user_id)->toBeNull()
        ->and($run->page)->toBeNull();
});
