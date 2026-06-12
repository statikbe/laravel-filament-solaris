<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Document;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Jobs\ProcessChunkJob;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;
use Statikbe\FilamentSolaris\Support\Batch\BatchRunConfig;
use Statikbe\FilamentSolaris\Testing\AiGenerateActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\GenerateFormComponent;
use Statikbe\FilamentSolaris\Tests\Fixtures\SeedCategory;

it('round-trips attachments through File::toArray()/fromArray()', function () {
    $file = Document::fromStorage('invoices/list.pdf', 'local');

    $serialized = array_map(fn ($f) => $f->toArray(), [$file]);

    $job = new ProcessChunkJob(
        config: new BatchRunConfig(
            actionName: 'importCategories',
            modelClass: SeedCategory::class,
            onlyColumns: [], exceptColumns: [], columnHints: [], columnEnums: [],
            identifierKey: '_index',
            writeTerminal: 'create',
            provider: null, model: null, timeout: null,
            runId: 'run-x',
            temperature: null, maxTokens: null, maxSteps: null, topP: null,
        ),
        prompt: 'Extract products from the PDF.',
        rowDescriptors: [],
        attachments: $serialized,
    );

    $rebuilt = (new ReflectionMethod(ProcessChunkJob::class, 'rehydrateAttachments'))->invoke($job);

    expect($rebuilt)->toHaveCount(1)
        ->and($rebuilt[0])->toBeInstanceOf(Document::class);
});

it('rejects a local-path attachment when queued (worker cannot reach it)', function () {
    $action = (new ReflectionClass(AiGenerateAction::class))->newInstanceWithoutConstructor();
    $serialize = (new ReflectionMethod(AiGenerateAction::class, 'serializeAttachments'))->getClosure($action);

    expect(fn () => $serialize([Document::fromPath('/tmp/x.pdf')]))
        ->toThrow(RuntimeException::class, 'disk-backed');
});

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

    // Bus::batch persists batch metadata to Laravel's own `job_batches` table.
    // Don't hand-roll its schema (it would drift if Laravel changes it) — run
    // Testbench's maintained jobs migration, which mirrors Laravel upstream.
    // Point the batch repo at the default (in-memory) connection so sync-driver
    // end-to-end tests can find the table.
    config()->set('queue.batching.database', config('database.default'));
    if (! Schema::hasTable('job_batches')) {
        foreach (glob(dirname(__DIR__, 2).'/vendor/orchestra/testbench-core/laravel/migrations/*_create_jobs_table.php') as $file) {
            (include $file)->up();
        }
    }
});

afterEach(function () {
    AiGenerateActionFake::reset();
    Schema::dropIfExists('seed_categories');
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $file) {
        (include $file)->down();
    }
});

it('from-scratch: creates every returned record when there are no input rows', function () {
    $run = SolarisBatchRun::create(['action_name' => 'pdfImport', 'status' => BatchRunStatus::Processing]);

    AiGenerateAction::fakeEach([[
        'records' => [
            ['_index' => 0, 'name' => 'Widget', 'slug' => 'widget'],
            ['_index' => 1, 'name' => 'Gadget', 'slug' => 'gadget'],
        ],
        'failed' => [],
    ]]);

    (new ProcessChunkJob(config: makeRunConfig($run->id), prompt: 'Extract products.', rowDescriptors: []))->handle();

    expect(SeedCategory::count())->toBe(2)
        ->and($run->refresh()->succeeded)->toBe(2);
});

it('end-to-end: queued from-scratch import with a disk attachment creates rows', function () {
    config()->set('queue.default', 'sync');
    Storage::fake('local');
    Storage::disk('local')->put('invoices/list.pdf', '%PDF-1.4 fake');

    AiGenerateAction::fakeEach([[
        'records' => [['_index' => 0, 'name' => 'Widget', 'slug' => 'widget']],
        'failed' => [],
    ]]);

    Livewire::test(GenerateFormComponent::class)->callAction('queuedPdfImport');

    expect(SeedCategory::where('name', 'Widget')->exists())->toBeTrue();
    expect(SolarisBatchRun::first()->status)->toBe(BatchRunStatus::Completed);
});
