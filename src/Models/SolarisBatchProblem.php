<?php

namespace Statikbe\FilamentSolaris\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;

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
        return FilamentSolaris::config()->getBatchProblemsTable();
    }

    public function batchRun(): BelongsTo
    {
        return $this->belongsTo(SolarisBatchRun::class, 'batch_run_id');
    }
}
