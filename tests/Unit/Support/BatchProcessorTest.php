<?php

use Statikbe\FilamentSolaris\Support\Batch\BatchGenerationException;
use Statikbe\FilamentSolaris\Support\Batch\BatchProcessor;
use Statikbe\FilamentSolaris\Support\Batch\BatchResponse;
use Statikbe\FilamentSolaris\Support\Batch\Sinks\InMemoryBatchSink;
use Statikbe\FilamentSolaris\Tests\Fixtures\SeedCategory;

/**
 * Build a processor whose AI call returns the given canned responses (one per
 * batch, in order) and whose writes are recorded into $written.
 *
 * @param  array<int, array<string, mixed>>  $responses  raw {records, failed} arrays
 */
function makeProcessor(string $identifierKey, array $responses, array &$written, ?InMemoryBatchSink $sink = null): BatchProcessor
{
    $i = 0;

    return new BatchProcessor(
        identifierKey: $identifierKey,
        generateResponse: function () use (&$i, $responses): BatchResponse {
            return BatchResponse::fromArray($responses[$i++] ?? ['records' => [], 'failed' => []]);
        },
        persistRecord: function (mixed $source, array $attributes) use (&$written): void {
            $written[] = $attributes;
        },
        sink: $sink ?? new InMemoryBatchSink,
    );
}

it('reconciles a happy-path batch and persists each matched record', function () {
    $sink = new InMemoryBatchSink;
    $written = [];
    $processor = makeProcessor('_index', [[
        'records' => [['_index' => 0, 'name' => 'A'], ['_index' => 1, 'name' => 'B']],
        'failed' => [],
    ]], $written, $sink);

    $processor->process([['x' => 0], ['x' => 1]], 10);

    expect($sink->succeeded())->toBe(2)
        ->and($sink->failures())->toBe([])
        ->and($sink->discarded())->toBe([])
        ->and($written)->toBe([['name' => 'A'], ['name' => 'B']]);
});

it('records a write error as a FailedRecord without aborting the batch', function () {
    $sink = new InMemoryBatchSink;
    $processor = new BatchProcessor(
        identifierKey: '_index',
        generateResponse: fn () => BatchResponse::fromArray([
            'records' => [['_index' => 0, 'name' => 'A'], ['_index' => 1, 'name' => 'B']],
            'failed' => [],
        ]),
        persistRecord: function (mixed $source, array $attributes): void {
            if (($attributes['name'] ?? null) === 'B') {
                throw new RuntimeException('boom');
            }
        },
        sink: $sink,
    );

    $processor->process([['x' => 0], ['x' => 1]], 10);

    expect($sink->succeeded())->toBe(1)
        ->and($sink->failures())->toHaveCount(1)
        ->and($sink->failures()[0]->identifier)->toBe(1)
        ->and($sink->failures()[0]->reason)->toStartWith('write error:');
});

it('counts input rows the AI never echoed as silent drops', function () {
    $sink = new InMemoryBatchSink;
    $written = [];
    $processor = makeProcessor('_index', [[
        'records' => [['_index' => 0, 'name' => 'A']],
        'failed' => [],
    ]], $written, $sink);

    $processor->process([['x' => 0], ['x' => 1]], 10);

    expect($sink->succeeded())->toBe(1)
        ->and($sink->failures())->toHaveCount(1)
        ->and($sink->failures()[0]->reason)->toBe('no response from AI');
});

it('discards a unmatched identifier and does not persist it', function () {
    $sink = new InMemoryBatchSink;
    $written = [];
    $processor = makeProcessor('_index', [[
        'records' => [['_index' => 0, 'name' => 'A'], ['_index' => 99, 'name' => 'ghost']],
        'failed' => [],
    ]], $written, $sink);

    $processor->process([['x' => 0]], 10);

    expect($sink->succeeded())->toBe(1)
        ->and($written)->toBe([['name' => 'A']])
        ->and($sink->discarded())->toHaveCount(1)
        ->and($sink->discarded()[0]->kind)->toBe('unmatched');
});

it('discards a re-echoed (duplicate) identifier, first wins', function () {
    $sink = new InMemoryBatchSink;
    $written = [];
    $processor = makeProcessor('_index', [[
        'records' => [['_index' => 0, 'name' => 'A'], ['_index' => 0, 'name' => 'dup']],
        'failed' => [],
    ]], $written, $sink);

    $processor->process([['x' => 0]], 10);

    expect($written)->toBe([['name' => 'A']])
        ->and($sink->discarded())->toHaveCount(1)
        ->and($sink->discarded()[0]->kind)->toBe('duplicate');
});

it('matches identifiers echoed as strings (coercion)', function () {
    $sink = new InMemoryBatchSink;
    $written = [];
    $processor = makeProcessor('_index', [[
        'records' => [['_index' => '0', 'name' => 'A']],
        'failed' => [],
    ]], $written, $sink);

    $processor->process([['x' => 0]], 10);

    expect($sink->succeeded())->toBe(1);
});

it('turns AI-reported failed entries into FailedRecords with their input', function () {
    $sink = new InMemoryBatchSink;
    $written = [];
    $processor = makeProcessor('_index', [[
        'records' => [['_index' => 0, 'name' => 'A']],
        'failed' => [['identifier' => 1, 'reason' => 'bad row']],
    ]], $written, $sink);

    $processor->process([['x' => 'first'], ['x' => 'second']], 10);

    expect($sink->succeeded())->toBe(1)
        ->and($sink->failures())->toHaveCount(1)
        ->and($sink->failures()[0]->identifier)->toBe(1)
        ->and($sink->failures()[0]->reason)->toBe('bad row')
        ->and($sink->failures()[0]->input)->toBe(['x' => 'second']);
});

it('echoes the primary key in updateRecords (PK) mode', function () {
    $sink = new InMemoryBatchSink;
    $written = [];
    $one = (new SeedCategory)->forceFill(['id' => 1]);
    $two = (new SeedCategory)->forceFill(['id' => 2]);
    $processor = makeProcessor('id', [[
        'records' => [['id' => 1, 'slug' => 'a'], ['id' => 2, 'slug' => 'b']],
        'failed' => [],
    ]], $written, $sink);

    $processor->process([$one, $two], 10);

    expect($sink->succeeded())->toBe(2)
        ->and($written)->toBe([['slug' => 'a'], ['slug' => 'b']]);
});

it('chunks rows into batchSize-sized AI calls', function () {
    $sink = new InMemoryBatchSink;
    $written = [];
    $processor = makeProcessor('_index', [
        ['records' => [['_index' => 0, 'n' => 1], ['_index' => 1, 'n' => 2]], 'failed' => []],
        ['records' => [['_index' => 0, 'n' => 3], ['_index' => 1, 'n' => 4]], 'failed' => []],
        ['records' => [['_index' => 0, 'n' => 5]], 'failed' => []],
    ], $written, $sink);

    $processor->process([['x' => 1], ['x' => 2], ['x' => 3], ['x' => 4], ['x' => 5]], 2);

    expect($sink->succeeded())->toBe(5)
        ->and($written)->toHaveCount(5);
});

it('marks a whole batch failed when generateResponse throws BatchGenerationException, and continues', function () {
    $sink = new InMemoryBatchSink;
    $calls = 0;
    $processor = new BatchProcessor(
        identifierKey: '_index',
        generateResponse: function () use (&$calls): BatchResponse {
            $calls++;
            if ($calls === 1) {
                throw new BatchGenerationException('provider down');
            }

            return BatchResponse::fromArray(['records' => [['_index' => 0, 'name' => 'C']], 'failed' => []]);
        },
        persistRecord: fn () => null,
        sink: $sink,
    );

    $processor->process([['x' => 1], ['x' => 2], ['x' => 3]], 2);

    expect($sink->succeeded())->toBe(1)
        ->and($sink->failures())->toHaveCount(2)
        ->and($sink->failures()[0]->reason)->toBe('provider down');
});

it('lets a non-BatchGenerationException throwable propagate (aborts the run)', function () {
    $processor = new BatchProcessor(
        identifierKey: '_index',
        generateResponse: fn () => throw new RuntimeException('genuine bug'),
        persistRecord: fn () => null,
        sink: new InMemoryBatchSink,
    );

    expect(fn () => $processor->process([['x' => 1]], 10))
        ->toThrow(RuntimeException::class, 'genuine bug');
});
