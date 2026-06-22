<?php

namespace Statikbe\FilamentSolaris\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;

/**
 * Prune old AiGenerateAction batch runs from the tracking tables. Deletes only
 * terminal runs (Completed/Failed) finished before the retention cutoff, in
 * chunks; their solaris_batch_problems rows cascade via the FK. Retention is
 * opt-in (--days or batch_tracking.database.pruning.after_days) — never deletes without one.
 */
class PruneBatchRunsCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'solaris:prune-batches
        {--days= : Delete runs finished more than this many days ago (overrides config).}
        {--force : Run without the production confirmation prompt.}';

    protected $description = 'Prune old AiGenerateAction batch runs (and their problems) from the tracking tables.';

    public function handle(): int
    {
        $days = $this->option('days') ?? config('filament-solaris.batch_tracking.database.pruning.after_days');

        if (! is_numeric($days) || (int) $days <= 0) {
            $this->error('No retention window: pass --days or set batch_tracking.database.pruning.after_days.');

            return self::FAILURE;
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $cutoff = now()->subDays((int) $days);
        $this->comment("Pruning batch runs finished before {$cutoff->toDateTimeString()}…");

        $chunk = (int) config('filament-solaris.batch_tracking.database.pruning.chunk', 500);
        $pruned = 0;

        do {
            $ids = SolarisBatchRun::query()
                ->whereIn('status', [BatchRunStatus::Completed, BatchRunStatus::Failed])
                ->where('finished_at', '<', $cutoff)
                ->limit($chunk)
                ->pluck('id');

            $deleted = $ids->isEmpty() ? 0 : SolarisBatchRun::whereKey($ids->all())->delete();
            $pruned += $deleted;
        } while ($deleted > 0);

        $this->info("Pruned {$pruned} batch run(s).");

        return self::SUCCESS;
    }
}
