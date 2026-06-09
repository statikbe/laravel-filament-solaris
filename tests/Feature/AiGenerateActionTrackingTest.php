<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Events\SolarisBatchCompleted;
use Statikbe\FilamentSolaris\Events\SolarisBatchStarted;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;
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
});

afterEach(function () {
    AiGenerateActionFake::reset();
    Schema::dropIfExists('seed_categories');
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $file) {
        (include $file)->down();
    }
});

it('persists a tracked run with failure + discard problems and fires events', function () {
    Event::fake([SolarisBatchStarted::class, SolarisBatchCompleted::class]);

    AiGenerateAction::fakeEach([[
        'records' => [
            ['_index' => 0, 'name' => 'A', 'slug' => 'a'],
            ['_index' => 1, 'name' => 'B', 'slug' => 'b'],
            ['_index' => 99, 'name' => 'ghost', 'slug' => 'ghost'],
        ],
        'failed' => [['identifier' => 2, 'reason' => 'bad row']],
    ]]);

    Livewire::test(GenerateFormComponent::class)->callAction('trackedImport');

    $run = SolarisBatchRun::sole();
    expect($run->status)->toBe(BatchRunStatus::Completed)
        ->and($run->succeeded)->toBe(2)
        ->and($run->failed)->toBe(1)
        ->and($run->discarded)->toBe(1)
        ->and($run->total)->toBe(3)
        ->and($run->page)->toBe(GenerateFormComponent::class)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->failures()->count())->toBe(1)
        ->and($run->discards()->count())->toBe(1)
        ->and($run->discards()->first()->reason)->toContain('unmatched');

    Event::assertDispatched(SolarisBatchStarted::class, fn ($e) => $e->runId === $run->id && $e->total === 3);
    Event::assertDispatched(SolarisBatchCompleted::class, fn ($e) => $e->succeeded === 2 && $e->failed === 1 && $e->discarded === 1);

    expect(SeedCategory::count())->toBe(2);
});

it('does not persist a run or fire events on the untracked default path', function () {
    Event::fake([SolarisBatchStarted::class, SolarisBatchCompleted::class]);

    AiGenerateAction::fakeEach([[
        'records' => [
            ['_index' => 0, 'name' => 'Tech', 'slug' => 'tech'],
            ['_index' => 1, 'name' => 'Science', 'slug' => 'science'],
            ['_index' => 2, 'name' => 'Art', 'slug' => 'art'],
        ],
        'failed' => [],
    ]]);

    Livewire::test(GenerateFormComponent::class)->callAction('importCategories');

    expect(SolarisBatchRun::count())->toBe(0);
    Event::assertNotDispatched(SolarisBatchStarted::class);
    Event::assertNotDispatched(SolarisBatchCompleted::class);
    expect(SeedCategory::count())->toBe(3);
});
