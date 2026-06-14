<?php

use Statikbe\FilamentSolaris\Actions\AiGenerateAction;
use Statikbe\FilamentSolaris\Support\Batch\Handlers\NotifyOnBatchCompletion;
use Statikbe\FilamentSolaris\Tests\Fixtures\RecordingHandler;

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
