<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Events\SolarisBatchCompleted;
use Statikbe\FilamentSolaris\Jobs\FinalizeRun;
use Statikbe\FilamentSolaris\Jobs\ProcessChunkJob;
use Statikbe\FilamentSolaris\Models\SolarisBatchProblem;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;
use Statikbe\FilamentSolaris\Support\Batch\BatchRunConfig;
use Statikbe\FilamentSolaris\Testing\AiGenerateActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\SeedCategory;

beforeEach(function () {
    AiGenerateActionFake::reset();
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
});

afterEach(function () {
    AiGenerateActionFake::reset();
    Schema::dropIfExists('seed_categories');
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $file) {
        (include $file)->down();
    }
});

function makeRunConfig(string $runId, string $writeTerminal = 'create', string $identifierKey = '_index'): BatchRunConfig
{
    return new BatchRunConfig(
        actionName: 'importCategories',
        modelClass: SeedCategory::class,
        onlyColumns: [], exceptColumns: [], columnHints: [], columnEnums: [],
        identifierKey: $identifierKey,
        writeTerminal: $writeTerminal,
        provider: null, model: null, timeout: null,
        runId: $runId,
        temperature: null, maxTokens: null, maxSteps: null, topP: null,
    );
}

it('processes one chunk: writes successes + persists failures to the run', function () {
    $run = SolarisBatchRun::create(['action_name' => 'importCategories', 'status' => BatchRunStatus::Processing, 'total' => 2]);

    AiGenerateAction::fakeEach([[
        'records' => [['_index' => 0, 'name' => 'A', 'slug' => 'a']],
        'failed' => [['identifier' => '1', 'reason' => 'bad row']],
    ]]);

    $job = new ProcessChunkJob(
        config: makeRunConfig($run->id),
        prompt: 'Create the records below.',
        rowDescriptors: [['_index' => 0, 'name' => 'A'], ['_index' => 1, 'name' => 'B']],
    );

    $job->handle();

    expect(SeedCategory::where('name', 'A')->exists())->toBeTrue();
    expect($run->refresh()->succeeded)->toBe(1);
    expect(SolarisBatchProblem::where('batch_run_id', $run->id)->where('type', 'failure')->count())->toBe(1);
});

it('finalizes a run: marks completed and fires SolarisBatchCompleted', function () {
    Event::fake([SolarisBatchCompleted::class]);

    $run = SolarisBatchRun::create([
        'action_name' => 'importCategories',
        'status' => BatchRunStatus::Processing,
        'total' => 3, 'succeeded' => 2, 'failed' => 1,
    ]);

    (new FinalizeRun($run->id, 'importCategories'))->handle();

    expect($run->refresh()->status)->toBe(BatchRunStatus::Completed)
        ->and($run->finished_at)->not->toBeNull();

    Event::assertDispatched(SolarisBatchCompleted::class, fn ($e) => $e->runId === $run->id && $e->succeeded === 2 && $e->failed === 1);
});
