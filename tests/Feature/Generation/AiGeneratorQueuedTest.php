<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Generation\AiGenerator;
use Statikbe\FilamentSolaris\Jobs\ProcessChunkJob;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;
use Statikbe\FilamentSolaris\Testing\AiGenerateActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\SeedCategory;

beforeEach(function () {
    AiGenerateActionFake::reset();
    foreach (glob(dirname(__DIR__, 3).'/database/migrations/*.php') as $file) {
        $migration = include $file;
        $migration->down();
        $migration->up();
    }
    Schema::dropIfExists('seed_categories');
    Schema::create('seed_categories', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('slug')->nullable();
        $table->timestamps();
    });

    config()->set('queue.batching.database', config('database.default'));
    if (! Schema::hasTable('job_batches')) {
        foreach (glob(dirname(__DIR__, 3).'/vendor/orchestra/testbench-core/laravel/migrations/*_create_jobs_table.php') as $file) {
            (include $file)->up();
        }
    }
});

afterEach(function () {
    AiGenerateActionFake::reset();
    Schema::dropIfExists('seed_categories');
    foreach (glob(dirname(__DIR__, 3).'/database/migrations/*.php') as $file) {
        (include $file)->down();
    }
});

it('returns the run handle and dispatches one ProcessChunkJob per chunk', function () {
    Bus::fake();

    $run = AiGenerator::make()
        ->eventSource('headless-queued')
        ->forModel(SeedCategory::class)
        ->sourceRecords([['name' => 'a'], ['name' => 'b'], ['name' => 'c']])
        ->batchSize(2)
        ->createRecords()
        ->runQueued();

    expect($run)->toBeInstanceOf(SolarisBatchRun::class)
        ->and($run->action_name)->toBe('headless-queued')
        ->and($run->status)->toBe(BatchRunStatus::Processing)
        ->and($run->total)->toBe(3);

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2
        && $batch->jobs->every(fn ($job) => $job instanceof ProcessChunkJob));
});

it('end-to-end: runQueued() processes the batch and completes the run (sync queue)', function () {
    config()->set('queue.default', 'sync');

    AiGenerateActionFake::fakeEach([
        ['records' => [['_index' => 0, 'name' => 'A', 'slug' => 'a'], ['_index' => 1, 'name' => 'B', 'slug' => 'b']], 'failed' => []],
        ['records' => [['_index' => 0, 'name' => 'C', 'slug' => 'c']], 'failed' => []],
    ]);

    $run = AiGenerator::make()
        ->eventSource('headless-queued')
        ->forModel(SeedCategory::class)
        ->sourceRecords([['name' => 'a'], ['name' => 'b'], ['name' => 'c']])
        ->batchSize(2)
        ->createRecords()
        ->runQueued();

    expect(SeedCategory::count())->toBe(3)
        ->and($run->refresh()->status)->toBe(BatchRunStatus::Completed)
        ->and($run->succeeded)->toBe(3);
});

it('dispatches a from-scratch queued single call (no source)', function () {
    config()->set('queue.default', 'sync');

    AiGenerateActionFake::activate([
        'records' => [['_index' => 0, 'name' => 'Seeded', 'slug' => 'seeded']],
        'failed' => [],
    ]);

    $run = AiGenerator::make()
        ->eventSource('headless-seed')
        ->forModel(SeedCategory::class)
        ->count(1)
        ->prompt('Generate a category')
        ->createRecords()
        ->runQueued();

    expect(SeedCategory::where('name', 'Seeded')->exists())->toBeTrue()
        ->and($run->refresh()->status)->toBe(BatchRunStatus::Completed);
});
