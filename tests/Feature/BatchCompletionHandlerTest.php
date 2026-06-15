<?php

use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;
use Statikbe\FilamentSolaris\Support\Batch\Handlers\NotifyOnBatchCompletion;
use Statikbe\FilamentSolaris\Testing\AiGenerateActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\GenerateFormComponent;
use Statikbe\FilamentSolaris\Tests\Fixtures\RecordingHandler;

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

function resolveHandlers(AiGenerateAction $action): array
{
    return (new ReflectionMethod($action, 'resolveCompletionHandlers'))->invoke($action);
}

it('defaults to the framework notification handler', function () {
    expect(resolveHandlers(AiGenerateAction::make('a')))->toBe([NotifyOnBatchCompletion::class]);
});

it('accepts a single handler class', function () {
    expect(resolveHandlers(AiGenerateAction::make('a')->onCompletion(RecordingHandler::class)))
        ->toBe([RecordingHandler::class]);
});

it('accepts and preserves the order of a handler list', function () {
    expect(resolveHandlers(AiGenerateAction::make('a')->onCompletion([NotifyOnBatchCompletion::class, RecordingHandler::class])))
        ->toBe([NotifyOnBatchCompletion::class, RecordingHandler::class]);
});

it('falls back to the configured default list', function () {
    config()->set('filament-solaris.batch_tracking.completion_handlers', [RecordingHandler::class]);
    expect(resolveHandlers(AiGenerateAction::make('a')))->toBe([RecordingHandler::class]);
});

it('invokes the completion handler once on an inline run with the right counts', function () {
    RecordingHandler::$received = [];
    AiGenerateAction::fakeEach([[
        'records' => [['_index' => 0, 'name' => 'A', 'slug' => 'a']],
        'failed' => [['identifier' => '1', 'reason' => 'bad']],
    ]]);

    Livewire::test(GenerateFormComponent::class)->callAction('completionInline');

    expect(RecordingHandler::$received)->toHaveCount(1)
        ->and(RecordingHandler::$received[0]->succeeded)->toBe(1)
        ->and(RecordingHandler::$received[0]->failed)->toBe(1)
        ->and(RecordingHandler::$received[0]->queued)->toBeFalse();
});
