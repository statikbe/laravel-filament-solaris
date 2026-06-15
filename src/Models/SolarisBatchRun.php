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
 * @property ?array $meta
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
        'meta' => 'array',
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

    /**
     * Resolve the user who triggered this run via the configured auth user model.
     * Not a relationship — the user model is config-driven, not a fixed FK. Returns
     * null when there is no user_id or the configured model isn't an Eloquent model.
     */
    public function getUser(): ?Model
    {
        $userModel = config('auth.providers.users.model');

        if ($this->user_id === null || ! is_string($userModel) || ! is_subclass_of($userModel, Model::class)) {
            return null;
        }

        return $userModel::find($this->user_id);
    }
}
