<?php

namespace Statikbe\FilamentSolaris\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;

/**
 * @property string $id
 * @property string $action_name
 * @property ?string $user_id
 * @property ?string $page
 * @property BatchRunStatus $status
 * @property ?int $total
 * @property int $succeeded
 * @property int $failed
 * @property int $discarded
 */
class SolarisBatchRun extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $attributes = [
        'succeeded' => 0,
        'failed' => 0,
        'discarded' => 0,
    ];

    protected $casts = [
        'status' => BatchRunStatus::class,
        'total' => 'integer',
        'succeeded' => 'integer',
        'failed' => 'integer',
        'discarded' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('filament-solaris.batch_tracking.runs_table', 'solaris_batch_runs');
    }

    public function problems(): HasMany
    {
        return $this->hasMany(SolarisBatchProblem::class, 'batch_run_id');
    }

    public function failures(): HasMany
    {
        return $this->problems()->where('type', 'failure');
    }

    public function discards(): HasMany
    {
        return $this->problems()->where('type', 'discard');
    }

    public function markCompleted(BatchRunStatus $status = BatchRunStatus::Completed): void
    {
        $this->update(['status' => $status, 'finished_at' => now()]);
    }
}
