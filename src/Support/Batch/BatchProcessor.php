<?php

namespace Statikbe\FilamentSolaris\Support\Batch;

use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Execution-agnostic records-loop engine: chunk source rows, get the AI's
 * structured response per chunk, reconcile output to input by identifier, write
 * the matches, and emit one BatchOutcome per batch to the sink. No logging, no
 * config, no Livewire — pure enough to unit-test with plain arrays.
 */
final class BatchProcessor
{
    /**
     * @param  Closure(array<int, array<string, mixed>|Model>): BatchResponse  $generateResponse  real or faked; throws BatchGenerationException on an AI-call failure
     * @param  Closure(mixed, array<string, mixed>): void  $persistRecord  create/update the record
     */
    public function __construct(
        private string $identifierKey,
        private Closure $generateResponse,
        private Closure $persistRecord,
        private BatchSink $sink,
    ) {}

    /**
     * @param  iterable<int, array<string, mixed>|Model>  $rows
     */
    public function process(iterable $rows, int $batchSize): void
    {
        foreach ($this->chunk($rows, $batchSize) as $batch) {
            try {
                $outcome = $this->reconcile($batch, ($this->generateResponse)($batch));
            } catch (BatchGenerationException $e) {
                $outcome = new BatchOutcome(0, $this->markFailed($batch, $e->getMessage()), []);
            }

            $this->sink->record($outcome);
        }
    }

    /**
     * @param  array<int, array<string, mixed>|Model>  $batch
     */
    public function reconcile(array $batch, BatchResponse $response): BatchOutcome
    {
        $identifierKey = $this->identifierKey;

        $lookup = [];
        foreach ($batch as $index => $row) {
            $lookup[(string) $this->identifierFor($index, $row)] = $row;
        }

        $succeeded = 0;
        $failures = [];
        $discarded = [];
        $consumed = [];

        foreach ($response->records as $outputRecord) {
            $id = $outputRecord[$identifierKey] ?? null;
            $key = $id === null ? null : (string) $id;

            if ($key !== null && isset($consumed[$key])) {
                $discarded[] = new DiscardedOutput('duplicate', $outputRecord, 'AiGenerateAction: duplicate identifier in records output, ignoring the repeat: '.json_encode($outputRecord));

                continue;
            }

            if ($key === null || ! isset($lookup[$key])) {
                $discarded[] = new DiscardedOutput('unmatched', $outputRecord, 'AiGenerateAction: unmatched or missing identifier in records output: '.json_encode($outputRecord));

                continue;
            }

            $row = $lookup[$key];
            unset($lookup[$key]);
            $consumed[$key] = true;

            $attrs = $outputRecord;
            unset($attrs[$identifierKey]);

            try {
                ($this->persistRecord)($row, $attrs);
                $succeeded++;
            } catch (\Throwable $e) {
                $failures[] = new FailedRecord(identifier: $id, reason: 'write error: '.$e->getMessage(), input: $row);
            }
        }

        foreach ($response->failed as $failure) {
            $id = $failure->identifier;
            $input = null;
            if ($id !== null && isset($lookup[(string) $id])) {
                $input = $lookup[(string) $id];
                unset($lookup[(string) $id]);
            }
            $failures[] = new FailedRecord(identifier: $id, reason: $failure->reason, input: $input);
        }

        foreach ($lookup as $id => $row) {
            $failures[] = new FailedRecord(identifier: $id, reason: 'no response from AI', input: $row);
        }

        return new BatchOutcome($succeeded, $failures, $discarded);
    }

    /**
     * @param  array<int, array<string, mixed>|Model>  $batch
     * @return array<int, FailedRecord>
     */
    private function markFailed(array $batch, string $reason): array
    {
        $failures = [];
        foreach ($batch as $index => $row) {
            $failures[] = new FailedRecord(identifier: $this->identifierFor($index, $row), reason: $reason, input: $row);
        }

        return $failures;
    }

    /**
     * Resolve a batch row's identifier: its position when keyed by `_index`,
     * otherwise the model key for Eloquent models, or the keyed array value
     * for plain-array descriptors (worker path).
     *
     * @param  array<string, mixed>|Model  $row
     */
    private function identifierFor(int $index, array|Model $row): mixed
    {
        if ($this->identifierKey === '_index') {
            return $index;
        }

        return $row instanceof Model
            ? $row->getKey()
            : ($row[$this->identifierKey] ?? null);
    }

    /**
     * Chunk rows into batchSize-sized slices. Delegates to the public static
     * `chunkRows()` so external callers (e.g. QueuedRunner) can chunk identically.
     *
     * @param  iterable<int, array<string, mixed>|Model>  $rows
     * @return iterable<int, array<int, array<string, mixed>|Model>>
     */
    private function chunk(iterable $rows, int $batchSize): iterable
    {
        return self::chunkRows($rows, $batchSize);
    }

    /**
     * @param  iterable<int, array<string, mixed>|Model>  $rows
     * @return iterable<int, array<int, array<string, mixed>|Model>>
     */
    public static function chunkRows(iterable $rows, int $batchSize): iterable
    {
        if ($rows instanceof Collection || $rows instanceof EloquentCollection) {
            foreach ($rows->chunk($batchSize) as $chunk) {
                yield array_values($chunk->all());
            }

            return;
        }

        $rowsArray = is_array($rows) ? $rows : iterator_to_array($rows);
        foreach (array_chunk($rowsArray, $batchSize) as $chunk) {
            yield $chunk;
        }
    }
}
