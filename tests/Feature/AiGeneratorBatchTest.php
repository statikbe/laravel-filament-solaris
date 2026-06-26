<?php

use Illuminate\Support\Facades\Schema;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Generation\AiGenerator;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;
use Statikbe\FilamentSolaris\Support\Batch\BatchResponse;
use Statikbe\FilamentSolaris\Support\Batch\BatchSummary;
use Statikbe\FilamentSolaris\Tests\Fixtures\SeedCategory;

beforeEach(function () {
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $file) {
        $migration = include $file;
        $migration->down();
        $migration->up();
    }

    Schema::create('seed_categories', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('slug')->nullable();
        $table->text('description')->nullable();
        $table->integer('weight')->default(0);
        $table->boolean('is_active')->default(true);
        $table->string('status')->default('draft');
        $table->integer('priority')->default(1);
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('seed_categories');
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $file) {
        (include $file)->down();
    }
});

it('runs a headless records-loop and creates records, returning a BatchSummary', function () {
    $summary = AiGenerator::make()
        ->forModel(SeedCategory::class)
        ->sourceRecords([['name' => 'a'], ['name' => 'b']])
        ->createRecords()
        ->responseGenerator(fn (array $batch): BatchResponse => BatchResponse::fromArray([
            'records' => [['_index' => 0, 'name' => 'A'], ['_index' => 1, 'name' => 'B']],
            'failed' => [],
        ]))
        ->runInline();

    expect($summary)->toBeInstanceOf(BatchSummary::class)
        ->and($summary->succeeded)->toBe(2)
        ->and($summary->failed)->toBe(0)
        ->and(SeedCategory::query()->pluck('name')->all())->toEqualCanonicalizing(['A', 'B']);
});

it('updates source models on a headless update records-loop', function () {
    $one = SeedCategory::create(['name' => 'old-1']);
    $two = SeedCategory::create(['name' => 'old-2']);

    AiGenerator::make()
        ->forModel(SeedCategory::class)
        ->sourceRecords(SeedCategory::all())
        ->updateRecords()
        ->responseGenerator(fn (array $batch): BatchResponse => BatchResponse::fromArray([
            'records' => [
                ['id' => $one->id, 'name' => 'new-1'],
                ['id' => $two->id, 'name' => 'new-2'],
            ],
            'failed' => [],
        ]))
        ->runInline();

    expect($one->fresh()->name)->toBe('new-1')
        ->and($two->fresh()->name)->toBe('new-2');
});

it('persists a tracked run and exposes its id on the summary', function () {
    $summary = AiGenerator::make()
        ->eventSource('headless-demo')
        ->forModel(SeedCategory::class)
        ->sourceRecords([['name' => 'a']])
        ->createRecords()
        ->trackBatchRuns()
        ->responseGenerator(fn (array $batch): BatchResponse => BatchResponse::fromArray([
            'records' => [['_index' => 0, 'name' => 'A']],
            'failed' => [],
        ]))
        ->runInline();

    expect($summary->runId)->not->toBeNull();

    $run = SolarisBatchRun::find($summary->runId);
    expect($run)->not->toBeNull()
        ->and($run->action_name)->toBe('headless-demo')
        ->and($run->status)->toBe(BatchRunStatus::Completed);
});
