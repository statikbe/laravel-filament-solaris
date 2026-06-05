<?php

use Illuminate\Support\Facades\Log;
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

it('invokes onPartialFailure with _index identifiers and input rows on a whole-batch error', function () {
    AiGenerateAction::fakeError('provider down');

    Livewire::test(GenerateFormComponent::class)
        ->callAction('partialFailureCapture')
        ->assertSet('handledData', [
            'succeeded' => 0,
            'failed' => 3,
            'total' => 3,
            'ids' => [0, 1, 2],
            'reasons' => ['provider down', 'provider down', 'provider down'],
            'inputs' => [
                ['name' => 'A', 'slug' => 'a'],
                ['name' => 'B', 'slug' => 'b'],
                ['name' => 'C', 'slug' => 'c'],
            ],
        ]);

    expect(SeedCategory::count())->toBe(0);
});

it('does NOT invoke onPartialFailure when every row succeeds', function () {
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

    Livewire::test(GenerateFormComponent::class)
        ->callAction('partialFailureCapture')
        ->assertSet('handledData', []);

    expect(SeedCategory::count())->toBe(3);
});

it('surfaces an AI-reported failed entry with its matched input row to the callback', function () {
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

    Livewire::test(GenerateFormComponent::class)
        ->callAction('partialFailureCapture')
        ->assertSet('handledData', [
            'succeeded' => 2,
            'failed' => 1,
            'total' => 3,
            'ids' => [2],
            'reasons' => ['ambiguous data'],
            'inputs' => [
                ['name' => 'C', 'slug' => 'c'],
            ],
        ]);

    expect(SeedCategory::count())->toBe(2);
});

it('passes PK identifiers and Model inputs to the callback for updateRecords failures', function () {
    SeedCategory::create(['name' => 'One', 'slug' => 'one']);
    SeedCategory::create(['name' => 'Two', 'slug' => 'two']);

    AiGenerateAction::fakeError('provider down');

    Livewire::test(GenerateFormComponent::class)
        ->callAction('partialFailureUpdateCapture')
        ->assertSet('handledData', [
            'ids' => [1, 2],
            'inputIsModel' => [true, true],
        ]);
});

it('logs the failure manifest even without an onPartialFailure callback', function () {
    Log::spy();

    AiGenerateAction::fakeError('provider down');

    Livewire::test(GenerateFormComponent::class)->callAction('importCategories');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'failed during a batched run')
            && ($context['failures'] ?? []) !== [])
        ->atLeast()->once();
});
