<?php

namespace Statikbe\FilamentSolaris\Support\Batch;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Statikbe\FilamentSolaris\Sanitizers\SanitizerExecutor;

/**
 * Filament-free record write-back for the records loop: creates a new model on
 * the create terminal, or updates the matched model on the update terminal —
 * either the live Eloquent instance (inline path) or a re-fetched model keyed by
 * its primary key (queued worker path, where the descriptor is a plain array).
 *
 * Reused by the inline batch path and the queued worker.
 */
class RecordWriter
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    /**
     * @param  class-string<Model>  $modelClass
     * @param  self::CREATE|self::UPDATE  $terminal
     */
    public function __construct(
        private string $modelClass,
        private string $terminal,
        private ?SanitizerExecutor $sanitizers = null,
    ) {}

    /**
     * @param  array<string, mixed>|Model  $row
     * @param  array<string, mixed>  $attrs
     */
    public function write(array|Model $row, array $attrs): void
    {
        $attrs = $this->sanitizers?->execute($attrs) ?? $attrs;

        if ($this->terminal === self::CREATE) {
            $this->modelClass::create($attrs);

            return;
        }

        // UPDATE
        if ($row instanceof Model) {
            $row->update($attrs);

            return;
        }

        // Worker path: descriptor is a plain array carrying the pk. Re-fetch fresh so we
        // write to current DB state; a row deleted mid-run becomes a recorded failure.
        $key = $row[(new ($this->modelClass)())->getKeyName()] ?? null;
        $model = $key === null ? null : $this->modelClass::find($key);

        if ($model === null) {
            throw new RuntimeException('updateRecords target no longer exists for identifier '.json_encode($key));
        }

        $model->update($attrs);
    }
}
