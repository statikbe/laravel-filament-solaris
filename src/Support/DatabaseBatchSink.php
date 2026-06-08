<?php

namespace Statikbe\FilamentSolaris\Support;

use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentSolaris\Models\SolarisBatchProblem;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;
use Statikbe\FilamentSolaris\Support\Contracts\BatchSink;

/**
 * Persists each batch's outcome to the run row (atomic count increments — safe
 * for the concurrent jobs piece #3 brings) + bulk-inserts its failures and
 * discards as typed problem rows. Holds nothing in memory beyond the run id.
 */
final class DatabaseBatchSink implements BatchSink
{
    public function __construct(private string $runId) {}

    public function record(BatchOutcome $outcome): void
    {
        if ($outcome->succeeded > 0) {
            SolarisBatchRun::query()->whereKey($this->runId)->increment('succeeded', $outcome->succeeded);
        }

        $now = now();
        $problems = [];

        if ($outcome->failures !== []) {
            SolarisBatchRun::query()->whereKey($this->runId)->increment('failed', count($outcome->failures));
            foreach ($outcome->failures as $failure) {
                $problems[] = [
                    'batch_run_id' => $this->runId,
                    'type' => 'failure',
                    'identifier' => $failure->identifier === null ? null : (string) $failure->identifier,
                    'reason' => $failure->reason,
                    'input' => $this->encode($failure->input),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($outcome->discarded !== []) {
            SolarisBatchRun::query()->whereKey($this->runId)->increment('discarded', count($outcome->discarded));
            foreach ($outcome->discarded as $discard) {
                $problems[] = [
                    'batch_run_id' => $this->runId,
                    'type' => 'discard',
                    'identifier' => isset($discard->record['_index']) ? (string) $discard->record['_index'] : null,
                    'reason' => $discard->reason,
                    'input' => $this->encode($discard->record),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($problems !== []) {
            SolarisBatchProblem::query()->insert($problems);
        }
    }

    private function encode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value instanceof Model ? ['id' => $value->getKey()] : $value);
    }
}
