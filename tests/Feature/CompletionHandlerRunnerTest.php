<?php

use Illuminate\Support\Facades\Log;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Support\Batch\BatchSummary;
use Statikbe\FilamentSolaris\Support\Batch\CompletionHandlerRunner;
use Statikbe\FilamentSolaris\Tests\Fixtures\RecordingHandler;
use Statikbe\FilamentSolaris\Tests\Fixtures\ThrowingHandler;

function summary(): BatchSummary
{
    return new BatchSummary('act', 'run-1', 1, 0, 0, BatchRunStatus::Completed, false);
}

beforeEach(fn () => RecordingHandler::$received = []);

it('runs handlers from the container in order', function () {
    (new CompletionHandlerRunner)->run([RecordingHandler::class], summary());

    expect(RecordingHandler::$received)->toHaveCount(1)
        ->and(RecordingHandler::$received[0]->runId)->toBe('run-1');
});

it('reports a throwing handler and still runs the rest', function () {
    Log::spy();

    (new CompletionHandlerRunner)->run([ThrowingHandler::class, RecordingHandler::class], summary());

    expect(RecordingHandler::$received)->toHaveCount(1); // the second handler still ran
});
