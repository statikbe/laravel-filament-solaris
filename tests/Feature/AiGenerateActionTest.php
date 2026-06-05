<?php

use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;
use Statikbe\FilamentSolaris\Agents\SolarisAgent;
use Statikbe\FilamentSolaris\Support\GenerationOptions;
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

it('runs per-row createRecords via ->sourceRecords() (import path)', function () {
    Schema::create('seed_categories', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('slug');
        $table->timestamps();
    });

    AiGenerateAction::fakeEach([
        [
            'records' => [
                ['_index' => 0, 'name' => 'Tech', 'slug' => 'tech'],
                ['_index' => 1, 'name' => 'Science', 'slug' => 'science'],
                ['_index' => 2, 'name' => 'Art', 'slug' => 'art'],
            ],
            'failed' => [],
        ],
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

    AiGenerateAction::fakeEach([
        [
            'records' => [
                ['_index' => 0, 'name' => 'A', 'slug' => 'a'],
                ['_index' => 1, 'slug' => 'b-missing-name'],   // NOT NULL on name → write error
                ['_index' => 2, 'name' => 'C', 'slug' => 'c'],
            ],
            'failed' => [],
        ],
    ]);

    Livewire::test(GenerateFormComponent::class)
        ->callAction('importCategories')
        ->assertNotified(filament_solaris_trans('notifications.batch_partial_failure', ['count' => 2, 'failed' => 1]));

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
        [
            'records' => [
                ['id' => 1, 'slug' => 'new-one'],
                ['id' => 2, 'slug' => 'new-two'],
            ],
            'failed' => [],
        ],
    ]);

    Livewire::test(GenerateFormComponent::class)->callAction('enrichCategories');

    expect(SeedCategory::pluck('slug', 'id')->all())->toEqualCanonicalizing([
        1 => 'new-one',
        2 => 'new-two',
    ]);

    Schema::dropIfExists('seed_categories');
});

it('errors when updateRecords is called without ->sourceRecords()', function () {
    AiGenerateAction::fake(['x' => 1]);

    expect(fn () => Livewire::test(GenerateFormComponent::class)->callAction('updateWithoutRecords'))
        ->toThrow(RuntimeException::class);
});

it('errors when ->sourceRecords() is combined with ->handleUsing()', function () {
    AiGenerateAction::fake(['x' => 1]);

    expect(fn () => Livewire::test(GenerateFormComponent::class)->callAction('sourceWithHandler'))
        ->toThrow(RuntimeException::class, 'does not run per batch');
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

it('passes the batch as $rows to the prompt closure per records loop', function () {
    Schema::create('seed_categories', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('slug');
        $table->timestamps();
    });

    // Two rows, one canned batch response — both should succeed.
    // The prompt closure references $rows (plural) — if it weren't injected
    // it'd throw ErrorException (undefined variable), failing the batch and
    // resulting in 0 rows created instead of 2.
    AiGenerateAction::fakeEach([
        [
            'records' => [
                ['_index' => 0, 'name' => 'Alpha', 'slug' => 'alpha'],
                ['_index' => 1, 'name' => 'Beta', 'slug' => 'beta'],
            ],
            'failed' => [],
        ],
    ]);

    Livewire::test(GenerateFormComponent::class)
        ->callAction('importWithRowPrompt')
        ->assertNotified(); // batch summary

    expect(SeedCategory::count())->toBe(2);

    Schema::dropIfExists('seed_categories');
});

it('counts every iteration as failed when fakeError is set on a records loop', function () {
    Schema::create('seed_categories', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('slug');
        $table->timestamps();
    });

    AiGenerateAction::fakeError('provider down');

    Livewire::test(GenerateFormComponent::class)
        ->callAction('importCategories')
        ->assertNotified(filament_solaris_trans('notifications.batch_partial_failure', ['count' => 0, 'failed' => 3]));

    expect(SeedCategory::count())->toBe(0);

    Schema::dropIfExists('seed_categories');
});

it('filters the row context to ->promptContextColumns() in the records block', function () {
    $action = AiGenerateAction::make('x')
        ->prompt('Do it.')
        ->forModel(SeedCategory::class)
        ->sourceRecords([['visible' => 'v', 'secret' => 's']])
        ->promptContextColumns(['visible'])
        ->createRecords();

    $ref = new ReflectionClass($action);
    $method = $ref->getMethod('appendRecordsBlock');
    $method->setAccessible(true);

    $instruction = $method->invoke($action, 'Do it.', [['visible' => 'v', 'secret' => 's']]);

    expect($instruction)->toContain('"visible": "v"')
        ->and($instruction)->not->toContain('"secret"');
});

it('falls back to config default_provider/default_model when no action override is set', function () {
    config([
        'filament-solaris.ai.default_provider' => ['anthropic'],
        'filament-solaris.ai.default_model' => 'claude-opus-4-7',
    ]);

    $action = AiGenerateAction::make('test')->outputSchema(fn ($schema) => ['x' => $schema->string()]);

    $ref = new ReflectionMethod($action, 'resolveProviderAndModel');
    $ref->setAccessible(true);
    $result = $ref->invoke($action);

    expect($result)->toBe(['provider' => ['anthropic'], 'model' => 'claude-opus-4-7']);
});

it('falls back to config default_timeout when no action override is set', function () {
    config(['filament-solaris.ai.default_timeout' => 42]);

    $action = AiGenerateAction::make('test')->outputSchema(fn ($schema) => ['x' => $schema->string()]);

    $ref = new ReflectionMethod($action, 'resolveTimeout');
    $ref->setAccessible(true);

    expect($ref->invoke($action))->toBe(42);
});

it('applies temperature, maxTokens, maxSteps, and topP to the agent', function () {
    $action = AiGenerateAction::make('test')
        ->outputSchema(fn ($schema) => ['x' => $schema->string()])
        ->temperature(0.9)
        ->maxTokens(2000)
        ->maxSteps(7)
        ->topP(0.85);

    $agent = new SolarisAgent;

    $ref = new ReflectionMethod($action, 'applyGenerationOptions');
    $ref->setAccessible(true);
    $ref->invoke($action, $agent);

    expect($agent->temperature())->toBe(0.9)
        ->and($agent->maxTokens())->toBe(2000)
        ->and($agent->maxSteps())->toBe(7)
        ->and($agent->topP())->toBe(0.85);
});

it('falls back to config defaults for AiGenerateAction generation options when not set', function () {
    config([
        'filament-solaris.ai.default_temperature' => 0.5,
        'filament-solaris.ai.default_max_tokens' => 1500,
        'filament-solaris.ai.default_max_steps' => 4,
        'filament-solaris.ai.default_top_p' => 0.9,
    ]);

    $action = AiGenerateAction::make('test')->outputSchema(fn ($schema) => ['x' => $schema->string()]);

    $ref = new ReflectionMethod($action, 'resolveGenerationOptions');
    $ref->setAccessible(true);

    $opts = $ref->invoke($action);

    expect($opts)->toBeInstanceOf(GenerationOptions::class)
        ->and($opts->temperature)->toBe(0.5)
        ->and($opts->maxTokens)->toBe(1500)
        ->and($opts->maxSteps)->toBe(4)
        ->and($opts->topP)->toBe(0.9);
});
