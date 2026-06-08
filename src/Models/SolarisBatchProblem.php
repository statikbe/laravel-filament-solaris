<?php

namespace Statikbe\FilamentSolaris\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $batch_run_id
 * @property string $type
 * @property ?string $identifier
 * @property string $reason
 * @property ?array<string, mixed> $input
 */
class SolarisBatchProblem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'input' => 'array',
    ];

    public function getTable(): string
    {
        return config('filament-solaris.batch_tracking.problems_table', 'solaris_batch_problems');
    }

    public function batchRun(): BelongsTo
    {
        return $this->belongsTo(SolarisBatchRun::class, 'batch_run_id');
    }
}
