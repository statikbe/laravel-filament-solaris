<?php

use Illuminate\Support\Facades\Event;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Events\SolarisBatchCompleted;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;
use Statikbe\FilamentSolaris\Support\Batch\BatchResponse;
use Statikbe\FilamentSolaris\Support\Batch\BatchSummary;
use Statikbe\FilamentSolaris\Support\Batch\Runners\InlineRunner;
use Statikbe\FilamentSolaris\Tests\Fixtures\RecordingHandler;

beforeEach(function () {
    RecordingHandler::$received = [];
});

function runInline(InlineRunner $runner, array $responses, array &$written, ?SolarisBatchRun $run = null, array $handlers = []): BatchSummary
{
    $i = 0;

    return $runner->run(
        actionName: 'demo',
        rows: [['name' => 'A'], ['name' => 'B']],
        batchSize: 10,
        identifierKey: '_index',
        generateResponse: function () use (&$i, $responses): BatchResponse {
            return BatchResponse::fromArray($responses[$i++] ?? ['records' => [], 'failed' => []]);
        },
        persistRecord: function (mixed $source, array $attrs) use (&$written): void {
            $written[] = $attrs;
        },
        run: $run,
        completionHandlers: $handlers,
        userInput: ['k' => 'v'],
    );
}

it('processes the batch, persists matched records, and returns a summary', function () {
    $written = [];
    $summary = runInline(new InlineRunner, [[
        'records' => [['_index' => 0, 'name' => 'AA'], ['_index' => 1, 'name' => 'BB']],
        'failed' => [],
    ]], $written);

    expect($summary)->toBeInstanceOf(BatchSummary::class)
        ->and($summary->actionName)->toBe('demo')
        ->and($summary->succeeded)->toBe(2)
        ->and($summary->failed)->toBe(0)
        ->and($summary->queued)->toBeFalse()
        ->and($summary->runId)->toBeNull()
        ->and($written)->toBe([['name' => 'AA'], ['name' => 'BB']]);
});

it('records unmatched AI output as failures in the summary', function () {
    $written = [];
    $summary = runInline(new InlineRunner, [[
        'records' => [['_index' => 0, 'name' => 'AA']],
        'failed' => [],
    ]], $written);

    // _index 1 never came back → reconciled as a failure.
    expect($summary->succeeded)->toBe(1)
        ->and($summary->failed)->toBe(1);
});

it('runs the completion handlers with the summary', function () {
    $written = [];
    runInline(new InlineRunner, [[
        'records' => [['_index' => 0, 'name' => 'AA'], ['_index' => 1, 'name' => 'BB']],
        'failed' => [],
    ]], $written, handlers: [RecordingHandler::class]);

    expect(RecordingHandler::$received)->toHaveCount(1)
        ->and(RecordingHandler::$received[0]->succeeded)->toBe(2);
});

it('marks a tracked run completed and fires SolarisBatchCompleted', function () {
    foreach (glob(dirname(__DIR__, 3).'/database/migrations/*.php') as $file) {
        $migration = include $file;
        $migration->down();
        $migration->up();
    }
    Event::fake([SolarisBatchCompleted::class]);

    $run = SolarisBatchRun::create(['action_name' => 'demo', 'status' => BatchRunStatus::Processing, 'total' => 2]);
    $written = [];

    $summary = runInline(new InlineRunner, [[
        'records' => [['_index' => 0, 'name' => 'AA'], ['_index' => 1, 'name' => 'BB']],
        'failed' => [],
    ]], $written, run: $run);

    expect($run->refresh()->status)->toBe(BatchRunStatus::Completed)
        ->and($summary->runId)->toBe($run->id);
    Event::assertDispatched(SolarisBatchCompleted::class);

    foreach (glob(dirname(__DIR__, 3).'/database/migrations/*.php') as $file) {
        (include $file)->down();
    }
});
