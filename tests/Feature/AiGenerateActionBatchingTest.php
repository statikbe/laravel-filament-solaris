<?php

use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;
use Statikbe\FilamentSolaris\Testing\AiGenerateActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\GenerateFormComponent;
use Statikbe\FilamentSolaris\Tests\Fixtures\SeedCategory;

beforeEach(function () {
    AiGenerateActionFake::reset();
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
});

it('processes a single batch when batchSize covers all rows', function () {
    AiGenerateAction::fakeEach([
        [
            'records' => [
                ['_index' => 0, 'name' => 'A', 'slug' => 'a'],
                ['_index' => 1, 'name' => 'B', 'slug' => 'b'],
                ['_index' => 2, 'name' => 'C', 'slug' => 'c'],
            ],
            'failed' => [],
        ],
    ]);

    Livewire::test(GenerateFormComponent::class)->callAction('batchedCreateFromSource');

    expect(SeedCategory::count())->toBe(3);

    $fake = AiGenerateActionFake::getInstance();
    $calls = (function () {
        return $this->calls;
    })->call($fake);
    expect($calls)->toHaveCount(1);
});

it('chunks into multiple batches when rows exceed batchSize', function () {
    foreach (range(1, 25) as $i) {
        SeedCategory::create(['name' => "Row {$i}", 'slug' => "row-{$i}"]);
    }

    AiGenerateAction::fakeEach([
        ['records' => array_map(fn ($i) => ['id' => $i, 'name' => "Updated {$i}", 'slug' => "updated-{$i}"], range(1, 10)), 'failed' => []],
        ['records' => array_map(fn ($i) => ['id' => $i, 'name' => "Updated {$i}", 'slug' => "updated-{$i}"], range(11, 20)), 'failed' => []],
        ['records' => array_map(fn ($i) => ['id' => $i, 'name' => "Updated {$i}", 'slug' => "updated-{$i}"], range(21, 25)), 'failed' => []],
    ]);

    Livewire::test(GenerateFormComponent::class)->callAction('batchedUpdate');

    $fake = AiGenerateActionFake::getInstance();
    $calls = (function () {
        return $this->calls;
    })->call($fake);
    expect($calls)->toHaveCount(3);
    expect(SeedCategory::where('name', 'like', 'Updated %')->count())->toBe(25);
});

it('treats batchSize=1 as a batch of 1 (same code path, no per-row legacy)', function () {
    AiGenerateAction::fakeEach([
        ['records' => [['_index' => 0, 'name' => 'X', 'slug' => 'x']], 'failed' => []],
        ['records' => [['_index' => 0, 'name' => 'Y', 'slug' => 'y']], 'failed' => []],
        ['records' => [['_index' => 0, 'name' => 'Z', 'slug' => 'z']], 'failed' => []],
    ]);

    Livewire::test(GenerateFormComponent::class)->callAction('batchedSmallBatch');

    expect(SeedCategory::count())->toBe(3);

    $fake = AiGenerateActionFake::getInstance();
    $calls = (function () {
        return $this->calls;
    })->call($fake);
    expect($calls)->toHaveCount(3);
    expect($calls[0]['batch'])->toHaveCount(1);
});

it('counts AI-reported failed entries with their reasons', function () {
    AiGenerateAction::fakeEach([
        [
            'records' => [
                ['_index' => 0, 'name' => 'A', 'slug' => 'a'],
                ['_index' => 1, 'name' => 'B', 'slug' => 'b'],
            ],
            'failed' => [
                ['identifier' => 2, 'reason' => 'ambiguous data'],
            ],
        ],
    ]);

    Livewire::test(GenerateFormComponent::class)->callAction('batchedCreateFromSource');

    expect(SeedCategory::count())->toBe(2);
});

it('counts silent drops (input rows missing from both records and failed)', function () {
    AiGenerateAction::fakeEach([
        [
            'records' => [
                ['_index' => 0, 'name' => 'A', 'slug' => 'a'],
            ],
            'failed' => [],
        ],
    ]);

    Livewire::test(GenerateFormComponent::class)->callAction('batchedCreateFromSource');

    expect(SeedCategory::count())->toBe(1);
});

it('logs hallucinated identifiers and skips them', function () {
    AiGenerateAction::fakeEach([
        [
            'records' => [
                ['_index' => 0, 'name' => 'A', 'slug' => 'a'],
                ['_index' => 99, 'name' => 'Hallucinated', 'slug' => 'hallucinated'],
            ],
            'failed' => [],
        ],
    ]);

    Livewire::test(GenerateFormComponent::class)->callAction('batchedCreateFromSource');

    expect(SeedCategory::count())->toBe(1);
    expect(SeedCategory::where('slug', 'hallucinated')->exists())->toBeFalse();
});

it('marks the whole batch failed when the AI call errors', function () {
    AiGenerateAction::fakeError('connection refused');

    Livewire::test(GenerateFormComponent::class)->callAction('batchedCreateFromSource');

    expect(SeedCategory::count())->toBe(0);
});

it('throws LogicException at execute time when a closure declares $row', function () {
    AiGenerateAction::fakeEach([
        ['records' => [['_index' => 0, 'name' => 'A', 'slug' => 'a']], 'failed' => []],
    ]);

    expect(fn () => Livewire::test(GenerateFormComponent::class)->callAction('batchedRowClosure'))
        ->toThrow(LogicException::class, '`$rows` (plural)');
});

it('passes the batch as $rows to prompt closures', function () {
    AiGenerateAction::fakeEach([
        ['records' => [['_index' => 0, 'name' => 'X', 'slug' => 'x']], 'failed' => []],
        ['records' => [['_index' => 0, 'name' => 'Y', 'slug' => 'y']], 'failed' => []],
        ['records' => [['_index' => 0, 'name' => 'Z', 'slug' => 'z']], 'failed' => []],
    ]);

    Livewire::test(GenerateFormComponent::class)->callAction('batchedSmallBatch');

    AiGenerateAction::assertCalledWithBatch(fn (array $batch) => count($batch) === 1);
});

it('surfaces single-call createRecords failed entries via notification', function () {
    // forModel + createRecords WITHOUT sourceRecords — single-call path (seedCategoriesCreate fixture).
    AiGenerateAction::fake([
        'records' => [
            ['_index' => 0, 'name' => 'Parsed 1', 'slug' => 'parsed-1'],
            ['_index' => 1, 'name' => 'Parsed 2', 'slug' => 'parsed-2'],
        ],
        'failed' => [
            ['identifier' => 'line 3: malformed', 'reason' => 'missing slug column'],
        ],
    ]);

    Livewire::test(GenerateFormComponent::class)->callAction('seedCategoriesCreate');

    expect(SeedCategory::count())->toBe(2);
});

it('strips the _index identifier before handing records to a forModel handler', function () {
    AiGenerateAction::fake(['records' => [
        ['_index' => 0, 'name' => 'Tech', 'slug' => 'tech'],
        ['_index' => 1, 'name' => 'Science', 'slug' => 'science'],
    ]]);

    // seedCategories is forModel + handleUsing; its handler does create($row).
    // If _index leaked through it would hit an unknown-column error → 0 created.
    Livewire::test(GenerateFormComponent::class)->callAction('seedCategories');

    expect(SeedCategory::count())->toBe(2)
        ->and(SeedCategory::pluck('slug')->all())->toEqualCanonicalizing(['tech', 'science']);
});

it('matches identifiers the AI echoed as strings (type coercion)', function () {
    AiGenerateAction::fakeEach([
        ['records' => [
            ['_index' => '0', 'name' => 'A', 'slug' => 'a'],
            ['_index' => '1', 'name' => 'B', 'slug' => 'b'],
            ['_index' => '2', 'name' => 'C', 'slug' => 'c'],
        ], 'failed' => []],
    ]);

    Livewire::test(GenerateFormComponent::class)->callAction('batchedCreateFromSource');

    expect(SeedCategory::count())->toBe(3);
});

it('treats a re-echoed (duplicate) identifier as unmatched and does not double-write', function () {
    AiGenerateAction::fakeEach([
        ['records' => [
            ['_index' => 0, 'name' => 'A', 'slug' => 'a'],
            ['_index' => 0, 'name' => 'Dup', 'slug' => 'dup'],
        ], 'failed' => []],
    ]);

    Livewire::test(GenerateFormComponent::class)->callAction('batchedCreateFromSource');

    // _index 0 is consumed by the first record; the duplicate is unmatched and skipped.
    expect(SeedCategory::count())->toBe(1)
        ->and(SeedCategory::where('slug', 'dup')->exists())->toBeFalse();
});

it('filters the $rows passed to a prompt closure to ->promptContextColumns()', function () {
    $action = AiGenerateAction::make('x')
        ->forModel(SeedCategory::class)
        ->promptContextColumns(['name'])
        ->sourceRecords([['name' => 'Visible', 'slug' => 'secret-slug']])
        ->prompt(fn (array $rows) => 'Rows: '.json_encode($rows))
        ->createRecords();

    $ref = new ReflectionMethod($action, 'buildBatchInstruction');
    $ref->setAccessible(true);

    $instruction = $ref->invoke($action, [['name' => 'Visible', 'slug' => 'secret-slug']], []);

    expect($instruction)->toContain('Visible')
        ->and($instruction)->not->toContain('secret-slug');
});

it('throws on a legacy $row closure even on the single-call path', function () {
    AiGenerateAction::fake(['records' => [], 'failed' => []]);

    expect(fn () => Livewire::test(GenerateFormComponent::class)->callAction('singleCallRowClosure'))
        ->toThrow(LogicException::class, '`$rows` (plural)');
});

it('resolves ->batchSize() as a closure with $userInput', function () {
    AiGenerateAction::fakeEach([
        ['records' => [['_index' => 0, 'name' => 'A', 'slug' => 'a'], ['_index' => 1, 'name' => 'B', 'slug' => 'b']], 'failed' => []],
        ['records' => [['_index' => 0, 'name' => 'C', 'slug' => 'c'], ['_index' => 1, 'name' => 'D', 'slug' => 'd']], 'failed' => []],
    ]);

    Livewire::test(GenerateFormComponent::class)->callAction('closureBatchSize', data: ['size' => '2']);

    // 4 rows at a resolved batchSize of 2 → 2 AI calls.
    $fake = AiGenerateActionFake::getInstance();
    $calls = (function () {
        return $this->calls;
    })->call($fake);
    expect($calls)->toHaveCount(2);
    expect(SeedCategory::count())->toBe(4);
});

it('throws when ->batchSize() resolves to a non-positive value', function () {
    AiGenerateAction::fakeEach([['records' => [], 'failed' => []]]);

    expect(fn () => Livewire::test(GenerateFormComponent::class)->callAction('zeroBatchSize'))
        ->toThrow(RuntimeException::class, 'positive integer');
});
