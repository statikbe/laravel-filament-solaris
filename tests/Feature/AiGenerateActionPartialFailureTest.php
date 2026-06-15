<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Psr\Log\LoggerInterface;
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;
use Statikbe\FilamentSolaris\Models\SolarisBatchProblem;
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

it('persists PK identifiers and source descriptors to batch problems for updateRecords failures', function () {
    SeedCategory::create(['name' => 'One', 'slug' => 'one']);
    SeedCategory::create(['name' => 'Two', 'slug' => 'two']);

    AiGenerateAction::fakeError('provider down');

    Livewire::test(GenerateFormComponent::class)->callAction('trackedUpdateCapture');

    $run = SolarisBatchRun::sole();
    $problems = SolarisBatchProblem::query()
        ->where('batch_run_id', $run->id)
        ->where('type', 'failure')
        ->orderBy('identifier')
        ->get();

    expect($problems)->toHaveCount(2)
        // updateRecords keys failures by primary key, not by a synthetic _index.
        ->and($problems->pluck('identifier')->all())->toBe(['1', '2'])
        // The persisted input is the PK descriptor for the source row (the run
        // is serializable, so the Model is reduced to its identifier).
        ->and($problems->first()->input)->toBe(['id' => 1])
        ->and($problems->last()->input)->toBe(['id' => 2]);
});

it('logs the failure manifest even without a completion handler', function () {
    Log::spy();

    AiGenerateAction::fakeError('provider down');

    Livewire::test(GenerateFormComponent::class)->callAction('importCategories');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'failed during a batched run')
            && ($context['failures'] ?? []) !== [])
        ->atLeast()->once();
});

it('does not log the manifest when failure logging is disabled', function () {
    config(['filament-solaris.failure_logging.enabled' => false]);
    Log::spy();

    AiGenerateAction::fakeError('provider down');

    Livewire::test(GenerateFormComponent::class)->callAction('importCategories');

    Log::shouldNotHaveReceived('warning');
});

it('routes the failure manifest to the configured log channel', function () {
    config(['filament-solaris.failure_logging.channel' => 'solaris']);

    $channelLogger = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->with('solaris')->andReturn($channelLogger);

    AiGenerateAction::fakeError('provider down');

    Livewire::test(GenerateFormComponent::class)->callAction('importCategories');

    $channelLogger->shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'failed during a batched run'))
        ->once();
});

it('sends a summary notification on a fully successful single-call createRecords run', function () {
    AiGenerateAction::fake([
        'records' => [
            ['_index' => 0, 'name' => 'A', 'slug' => 'a'],
            ['_index' => 1, 'name' => 'B', 'slug' => 'b'],
        ],
        'failed' => [],
    ]);

    Livewire::test(GenerateFormComponent::class)
        ->callAction('seedCategoriesCreateCapture')
        ->assertNotified();

    expect(SeedCategory::count())->toBe(2);
});

it('completes the run and still notifies when a completion handler throws', function () {
    AiGenerateAction::fakeError('provider down');

    // The first handler (ThrowingHandler) throws; the runner report()s it and
    // continues to NotifyOnBatchCompletion, so the run still completes + notifies.
    Livewire::test(GenerateFormComponent::class)
        ->callAction('throwingCompletion')
        ->assertNotified();
});

it('aggregates success and failure across multiple batches into one summary notification', function () {
    AiGenerateAction::fakeEach([
        ['records' => [['_index' => 0, 'name' => 'A', 'slug' => 'a'], ['_index' => 1, 'name' => 'B', 'slug' => 'b']], 'failed' => []],
        ['records' => [['_index' => 0, 'name' => 'C', 'slug' => 'c']], 'failed' => [['identifier' => 1, 'reason' => 'bad row']]],
    ]);

    Livewire::test(GenerateFormComponent::class)
        ->callAction('crossBatchCapture')
        ->assertNotified();

    expect(SeedCategory::count())->toBe(3);
});

it('routes reconcile anomalies (unmatched identifiers) to the failure channel, not report()', function () {
    config(['filament-solaris.failure_logging.channel' => 'solaris']);

    $channelLogger = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->with('solaris')->andReturn($channelLogger);

    AiGenerateAction::fakeEach([
        ['records' => [
            ['_index' => 0, 'name' => 'A', 'slug' => 'a'],
            ['_index' => 99, 'name' => 'Hallucinated', 'slug' => 'unmatched'],
        ], 'failed' => []],
    ]);

    Livewire::test(GenerateFormComponent::class)->callAction('batchedCreateFromSource');

    // The unmatched record went to the gated failure channel (had it been
    // report()ed, it would never reach Log::channel()).
    $channelLogger->shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'unmatched'))
        ->once();

    expect(SeedCategory::count())->toBe(1);
});

it('does not log reconcile anomalies when failure logging is disabled', function () {
    config(['filament-solaris.failure_logging.enabled' => false]);
    Log::spy();

    AiGenerateAction::fakeEach([
        ['records' => [
            ['_index' => 0, 'name' => 'A', 'slug' => 'a'],
            ['_index' => 99, 'name' => 'Hallucinated', 'slug' => 'unmatched'],
        ], 'failed' => []],
    ]);

    Livewire::test(GenerateFormComponent::class)->callAction('batchedCreateFromSource');

    Log::shouldNotHaveReceived('warning');
    expect(SeedCategory::count())->toBe(1);
});
