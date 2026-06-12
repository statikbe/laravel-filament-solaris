<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Events\SolarisBatchCompleted;
use Statikbe\FilamentSolaris\Jobs\FinalizeRun;
use Statikbe\FilamentSolaris\Jobs\ProcessChunkJob;
use Statikbe\FilamentSolaris\Models\SolarisBatchProblem;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;
use Statikbe\FilamentSolaris\Support\Batch\BatchRunConfig;
use Statikbe\FilamentSolaris\Support\Batch\Runners\QueuedRunner;
use Statikbe\FilamentSolaris\Testing\AiGenerateActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\GenerateFormComponent;
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

    // Bus::batch persists batch metadata to job_batches; Testbench has no such
    // table. Point the batch repo at the default (in-memory) connection and
    // create the canonical schema so queued end-to-end tests can run under sync.
    config()->set('queue.batching.database', config('database.default'));
    Schema::dropIfExists('job_batches');
    Schema::create('job_batches', function ($table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->integer('total_jobs');
        $table->integer('pending_jobs');
        $table->integer('failed_jobs');
        $table->longText('failed_job_ids');
        $table->mediumText('options')->nullable();
        $table->integer('cancelled_at')->nullable();
        $table->integer('created_at');
        $table->integer('finished_at')->nullable();
    });
});

afterEach(function () {
    AiGenerateActionFake::reset();
    Schema::dropIfExists('job_batches');
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

it('dispatches one ProcessChunkJob per chunk with pre-rendered prompt + descriptors', function () {
    Bus::fake();

    $run = SolarisBatchRun::create(['action_name' => 'importCategories', 'status' => BatchRunStatus::Processing, 'total' => 3]);
    $config = makeRunConfig($run->id);

    $chunks = [
        [['_index' => 0, 'name' => 'A'], ['_index' => 1, 'name' => 'B']],
        [['_index' => 0, 'name' => 'C']],
    ];

    (new QueuedRunner)->dispatch(
        run: $run,
        config: $config,
        chunks: $chunks,
        renderPrompt: fn (array $chunk) => 'Prompt for '.count($chunk).' rows',
        buildDescriptors: fn (array $chunk) => $chunk,
    );

    Bus::assertBatched(function ($batch) {
        return $batch->jobs->count() === 2
            && $batch->jobs->first()->prompt === 'Prompt for 2 rows';
    });
});

it('end-to-end: ->queued() dispatches a batch that creates all rows', function () {
    config()->set('queue.default', 'sync');

    AiGenerateAction::fakeEach([
        ['records' => [['_index' => 0, 'name' => 'A', 'slug' => 'a'], ['_index' => 1, 'name' => 'B', 'slug' => 'b']], 'failed' => []],
        ['records' => [['_index' => 0, 'name' => 'C', 'slug' => 'c']], 'failed' => []],
    ]);

    Livewire::test(GenerateFormComponent::class)->callAction('queuedImport');

    expect(SeedCategory::count())->toBe(3);
    $run = SolarisBatchRun::first();
    expect($run->status)->toBe(BatchRunStatus::Completed)
        ->and($run->succeeded)->toBe(3);
});
