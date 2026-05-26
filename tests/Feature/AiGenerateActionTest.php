<?php

use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;
use Statikbe\FilamentSolaris\Testing\AiGenerateActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\GenerateFormComponent;
use Statikbe\FilamentSolaris\Tests\Fixtures\SeedCategory;

beforeEach(fn () => AiGenerateActionFake::reset());
afterEach(fn () => AiGenerateActionFake::reset());

it('runs the handler with the decoded structured data (custom schema)', function () {
    AiGenerateAction::fake(['taxonomy' => [['name' => 'Tech'], ['name' => 'Science']]]);

    Livewire::test(GenerateFormComponent::class)
        ->callAction('buildTaxonomy')
        ->assertSet('handledData', ['taxonomy' => [['name' => 'Tech'], ['name' => 'Science']]]);

    AiGenerateAction::assertHandledWith(fn (array $data) => expect($data['taxonomy'])->toHaveCount(2));
});

it('errors when no handler is configured', function () {
    AiGenerateAction::fake(['a' => 'x']);

    expect(fn () => Livewire::test(GenerateFormComponent::class)->callAction('missingHandler'))
        ->toThrow(RuntimeException::class, 'requires a terminal: ->handleUsing()');
});

it('errors when no schema source is configured', function () {
    AiGenerateAction::fake(['a' => 'x']);

    expect(fn () => Livewire::test(GenerateFormComponent::class)->callAction('missingSchema'))
        ->toThrow(RuntimeException::class, 'requires a schema source: ->outputSchema()');
});

it('shows an error notification when the handler throws', function () {
    AiGenerateAction::fake(['a' => 'x']);

    Livewire::test(GenerateFormComponent::class)
        ->callAction('throwingHandler')
        ->assertNotified();
});

it('seeds records via forModel and the $records handler arg', function () {
    Schema::create('seed_categories', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('slug');
        $table->timestamps();
    });

    AiGenerateAction::fake(['records' => [
        ['name' => 'Tech', 'slug' => 'tech'],
        ['name' => 'Science', 'slug' => 'science'],
    ]]);

    Livewire::test(GenerateFormComponent::class)->callAction('seedCategories');

    expect(SeedCategory::count())->toBe(2)
        ->and(SeedCategory::pluck('slug')->all())->toEqualCanonicalizing(['tech', 'science']);

    AiGenerateAction::assertHandledWith(fn (array $data) => expect($data['records'])->toHaveCount(2));

    Schema::dropIfExists('seed_categories');
});

it('errors when both schema sources are configured', function () {
    AiGenerateAction::fake(['a' => 'x']);

    expect(fn () => Livewire::test(GenerateFormComponent::class)->callAction('bothSources'))
        ->toThrow(RuntimeException::class, 'not both');
});

it('injects $record into the handler', function () {
    AiGenerateAction::fake(['taxonomy' => []]);

    Livewire::test(GenerateFormComponent::class)
        ->callAction('recordAware')
        ->assertSet('handledData', ['name' => 'Ctx', 'data' => ['taxonomy' => []]]);
});

it('shows an error notification and skips the handler under fakeError', function () {
    AiGenerateAction::fakeError('provider down');

    Livewire::test(GenerateFormComponent::class)
        ->callAction('buildTaxonomy')
        ->assertNotified()
        ->assertSet('handledData', []);
});

it('stores columnHint and columnEnum on the action', function () {
    $action = AiGenerateAction::make('x')
        ->prompt('y')
        ->forModel(SeedCategory::class)
        ->columnHint('slug', 'kebab-case')
        ->columnEnum('name', ['A', 'B'])
        ->handleUsing(fn () => null);

    $ref = new ReflectionClass($action);

    $hintsProp = $ref->getProperty('columnHints');
    $enumsProp = $ref->getProperty('columnEnums');

    expect($hintsProp->getValue($action))->toBe(['slug' => 'kebab-case'])
        ->and($enumsProp->getValue($action))->toBe(['name' => ['A', 'B']]);
});

it('runs per-row createRecords via ->records() (import path)', function () {
    Schema::create('seed_categories', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('slug');
        $table->timestamps();
    });

    AiGenerateAction::fakeEach([
        ['name' => 'Tech', 'slug' => 'tech'],
        ['name' => 'Science', 'slug' => 'science'],
        ['name' => 'Art', 'slug' => 'art'],
    ]);

    Livewire::test(GenerateFormComponent::class)
        ->callAction('importCategories')
        ->assertNotified();   // batch summary

    expect(SeedCategory::count())->toBe(3)
        ->and(SeedCategory::pluck('slug')->all())->toEqualCanonicalizing(['tech', 'science', 'art']);

    Schema::dropIfExists('seed_categories');
});

it('continues past per-row failures and reports a partial-failure summary', function () {
    Schema::create('seed_categories', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('slug');
        $table->timestamps();
    });

    // Three responses, middle one missing the required 'name' to trigger a write error.
    AiGenerateAction::fakeEach([
        ['name' => 'A', 'slug' => 'a'],
        ['slug' => 'b-missing-name'],          // SQLite NOT NULL on `name` will throw
        ['name' => 'C', 'slug' => 'c'],
    ]);

    Livewire::test(GenerateFormComponent::class)->callAction('importCategories');

    expect(SeedCategory::count())->toBe(2);

    Schema::dropIfExists('seed_categories');
});

it('runs per-row updateRecords (enrich by key)', function () {
    Schema::create('seed_categories', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('slug');
        $table->timestamps();
    });

    SeedCategory::create(['name' => 'One', 'slug' => 'old-one']);
    SeedCategory::create(['name' => 'Two', 'slug' => 'old-two']);

    AiGenerateAction::fakeEach([
        ['slug' => 'new-one'],
        ['slug' => 'new-two'],
    ]);

    Livewire::test(GenerateFormComponent::class)->callAction('enrichCategories');

    expect(SeedCategory::pluck('slug', 'id')->all())->toEqualCanonicalizing([
        1 => 'new-one',
        2 => 'new-two',
    ]);

    Schema::dropIfExists('seed_categories');
});

it('errors when updateRecords is called without ->records()', function () {
    AiGenerateAction::fake(['x' => 1]);

    expect(fn () => Livewire::test(GenerateFormComponent::class)->callAction('updateWithoutRecords'))
        ->toThrow(RuntimeException::class);
});

it('errors when both create and update terminals are set', function () {
    AiGenerateAction::fake(['x' => 1]);

    expect(fn () => Livewire::test(GenerateFormComponent::class)->callAction('bothTerminals'))
        ->toThrow(RuntimeException::class, 'mutually exclusive');
});

it('errors when createRecords is used without ->forModel()', function () {
    AiGenerateAction::fake(['x' => 1]);

    expect(fn () => Livewire::test(GenerateFormComponent::class)->callAction('createWithoutModel'))
        ->toThrow(RuntimeException::class, 'require ->forModel()');
});
