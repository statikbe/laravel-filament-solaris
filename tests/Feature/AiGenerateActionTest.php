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
        ->toThrow(RuntimeException::class, 'requires a ->handleUsing()');
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
