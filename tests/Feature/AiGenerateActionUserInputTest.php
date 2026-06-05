<?php

use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;
use Statikbe\FilamentSolaris\Support\UserInput;
use Statikbe\FilamentSolaris\Testing\AiGenerateActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\GenerateFormComponent;
use Statikbe\FilamentSolaris\Tests\Fixtures\SeedCategory;

beforeEach(fn () => AiGenerateActionFake::reset());
afterEach(fn () => AiGenerateActionFake::reset());

it('exposes hasUserInput()=true when ->userInput() is set', function () {
    $action = AiGenerateAction::make('test')
        ->userInput(UserInput::make()->fields([Textarea::make('focus')]))
        ->outputSchema(fn ($s) => ['x' => $s->string()]);

    expect($action->hasUserInput())->toBeTrue()
        ->and($action->getUserInputFormSchema())->toHaveCount(1);
});

it('exposes hasUserInput()=false when ->userInput() is NOT set', function () {
    $action = AiGenerateAction::make('test')
        ->outputSchema(fn ($s) => ['x' => $s->string()]);

    expect($action->hasUserInput())->toBeFalse()
        ->and($action->getUserInputFormSchema())->toBe([]);
});

it('injects $userInput into the ->prompt() closure (single-call path)', function () {
    AiGenerateAction::fake(['x' => 'ok']);

    Livewire::test(GenerateFormComponent::class)
        ->callAction('userInputSingleCall', data: ['focus' => 'SEO']);

    AiGenerateAction::assertCalledWithUserInput(fn (array $userInput) => ($userInput['focus'] ?? null) === 'SEO');
});

it('injects $userInput into the ->handleUsing() closure', function () {
    AiGenerateAction::fake(['x' => 'ok']);

    Livewire::test(GenerateFormComponent::class)
        ->callAction('userInputSingleCall', data: ['focus' => 'conversational'])
        ->assertSet('handledData', [
            'data' => ['x' => 'ok'],
            'userInput' => ['focus' => 'conversational'],
        ]);
});

it('injects $userInput into the ->sourceRecords() closure', function () {
    Schema::create('seed_categories', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('slug');
        $table->timestamps();
    });

    AiGenerateAction::fakeEach([
        [
            'records' => [
                ['_index' => 0, 'name' => 'Row1', 'slug' => 'row-1'],
                ['_index' => 1, 'name' => 'Row2', 'slug' => 'row-2'],
                ['_index' => 2, 'name' => 'Row3', 'slug' => 'row-3'],
            ],
            'failed' => [],
        ],
    ]);

    Livewire::test(GenerateFormComponent::class)
        ->callAction('userInputSourceRecordsClosure', data: ['count_hint' => '3']);

    expect(SeedCategory::count())->toBe(3);

    Schema::dropIfExists('seed_categories');
});

it('injects $userInput into the per-row ->prompt() closure in the records loop', function () {
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
                ['_index' => 1, 'name' => 'B', 'slug' => 'b'],
            ],
            'failed' => [],
        ],
    ]);

    Livewire::test(GenerateFormComponent::class)
        ->callAction('userInputCreateRecordsLoop', data: ['focus' => 'brevity']);

    AiGenerateAction::assertCalledWithUserInput(fn (array $userInput) => ($userInput['focus'] ?? null) === 'brevity');

    expect(SeedCategory::count())->toBe(2);

    Schema::dropIfExists('seed_categories');
});

it('appends the ## User context block to the single-call instruction', function () {
    $action = AiGenerateAction::make('test')
        ->prompt('Do the thing.')
        ->outputSchema(fn ($s) => ['x' => $s->string()]);

    $ref = new ReflectionMethod($action, 'resolveInstruction');
    $ref->setAccessible(true);

    $result = $ref->invoke($action, ['focus' => 'SEO']);

    expect($result)->toContain('Do the thing.')
        ->and($result)->toContain('## User context')
        ->and($result)->toContain('"focus": "SEO"');
});

it('appends ## User context BEFORE ## Records in the batch instruction', function () {
    $action = AiGenerateAction::make('test')
        ->prompt('Process.')
        ->forModel(SeedCategory::class)
        ->sourceRecords([['name' => 'Tech', 'slug' => 'tech']])
        ->createRecords();

    $ref = new ReflectionMethod($action, 'buildBatchInstruction');
    $ref->setAccessible(true);

    $result = $ref->invoke($action, [['name' => 'Tech', 'slug' => 'tech']], ['focus' => 'SEO']);

    $userCtxPos = strpos($result, '## User context');
    $recordsPos = strpos($result, '## Records');

    expect($userCtxPos)->toBeInt()
        ->and($recordsPos)->toBeInt()
        ->and($userCtxPos)->toBeLessThan($recordsPos);
});

it('omits ## User context when $userInput is empty or all-null', function () {
    $action = AiGenerateAction::make('test')
        ->prompt('Do the thing.')
        ->outputSchema(fn ($s) => ['x' => $s->string()]);

    $ref = new ReflectionMethod($action, 'resolveInstruction');
    $ref->setAccessible(true);

    $emptyResult = $ref->invoke($action, []);
    $allNullResult = $ref->invoke($action, ['x' => null, 'y' => '']);

    expect($emptyResult)->not->toContain('## User context')
        ->and($allNullResult)->not->toContain('## User context');
});

it('throws BadMethodCallException when ->withDefaultUserInput() is called on AiGenerateAction', function () {
    $action = AiGenerateAction::make('test')
        ->outputSchema(fn ($s) => ['x' => $s->string()]);

    expect(fn () => $action->withDefaultUserInput())->toThrow(BadMethodCallException::class);
});
