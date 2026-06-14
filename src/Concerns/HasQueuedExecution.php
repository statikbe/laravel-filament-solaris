<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Files\File;
use RuntimeException;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;
use Statikbe\FilamentSolaris\Support\Batch\BatchProcessor;
use Statikbe\FilamentSolaris\Support\Batch\BatchRunConfig;
use Statikbe\FilamentSolaris\Support\Batch\Runners\QueuedRunner;

/**
 * Queued-execution concern for AiGenerateAction: the `->queued()` opt-in plus the
 * dispatch orchestration that turns the action's fluent config into a serializable
 * plan and hands it to {@see QueuedRunner} (which owns the Bus::batch mechanics).
 *
 * Lives in a trait — not QueuedRunner — because building the run config + chunk
 * descriptors reads the action's resolved state ($this->modelClass, columns,
 * identifier key, generation options, buildBatchInstruction…). A trait keeps that
 * access without widening the action's public API or coupling the transport layer
 * back to the action.
 */
trait HasQueuedExecution
{
    protected bool|Closure $queued = false;

    /**
     * Run the records loop on the queue (Bus::batch of per-chunk jobs) instead of
     * inline in the request. Opt-in; requires ->forModel() + ->createRecords()/
     * ->updateRecords(). See spec 30.
     */
    public function queued(bool|Closure $queued = true): static
    {
        $this->queued = $queued;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $userInput
     */
    protected function isQueued(array $userInput = []): bool
    {
        return (bool) ($this->queued instanceof Closure
            ? $this->evaluate($this->queued, ['userInput' => $userInput])
            : $this->queued);
    }

    /**
     * Dispatch a queued records-loop run: snapshot config to scalars, pre-render each
     * chunk's prompt + descriptors in-request, hand off to QueuedRunner (Bus::batch).
     *
     * Unlike the inline path (which only persists a run when ->trackBatchRuns() is
     * on), queued mode ALWAYS creates a SolarisBatchRun: the run row is the only
     * place per-chunk outcomes can be aggregated across jobs, so its id is required
     * by every ProcessChunkJob. Queued therefore implies tracking by construction.
     *
     * @param  iterable<int, array<string, mixed>|Model>  $rows
     * @param  array<string, mixed>  $userInput
     */
    protected function dispatchQueuedRun(iterable $rows, array $userInput, mixed $provider, ?string $model, ?int $timeout, int $batchSize): void
    {
        $run = $this->startBatchRun($rows, $userInput);
        $config = $this->buildRunConfig($run, $provider, $model, $timeout);

        $attachments = $this->serializeAttachments($this->resolveAttachments($userInput));

        (new QueuedRunner)->dispatch(
            run: $run,
            config: $config,
            chunks: BatchProcessor::chunkRows($rows, $batchSize),
            renderPrompt: fn (array $chunk): string => $this->buildBatchInstruction($chunk, $userInput),
            buildDescriptors: fn (array $chunk): array => $this->buildChunkDescriptors($chunk),
            attachments: $attachments,
        );

        $this->sendQueuedStartedNotification();
    }

    /**
     * Queued single-call / from-scratch generation (no ->sourceRecords()): one
     * ProcessChunkJob with no descriptors + serialized attachments. Reuses the
     * from-scratch branch (rowDescriptors === []) on the worker.
     *
     * @param  array<string, mixed>  $userInput
     */
    protected function dispatchQueuedSingleCall(array $userInput): void
    {
        $instruction = $this->resolveInstruction($userInput);
        ['provider' => $provider, 'model' => $model] = $this->resolveProviderAndModel();
        $timeout = $this->resolveTimeout();

        $run = $this->startBatchRun(null, $userInput);   // total unknown until the model answers
        $config = $this->buildRunConfig($run, $provider, $model, $timeout);

        $attachments = $this->serializeAttachments($this->resolveAttachments($userInput));

        (new QueuedRunner)->dispatchSingleCall($run, $config, $instruction, $attachments);

        $this->sendQueuedStartedNotification();
    }

    /**
     * Snapshot this action's config into the pure-scalar BatchRunConfig the worker
     * rebuilds the agent + schema + write-back from. Shared by both queued entry
     * points (chunked records-loop and one-job single-call); provider/model/timeout
     * are passed in because each caller resolves them slightly differently.
     */
    protected function buildRunConfig(SolarisBatchRun $run, mixed $provider, ?string $model, ?int $timeout): BatchRunConfig
    {
        $options = $this->resolveGenerationOptions();

        return new BatchRunConfig(
            actionName: $this->getName(),
            modelClass: $this->modelClass,
            onlyColumns: $this->onlyColumns,
            exceptColumns: $this->exceptColumns,
            columnHints: $this->columnHints,
            columnEnums: $this->columnEnums,
            identifierKey: $this->resolveIdentifierKey(),
            writeTerminal: $this->writeTerminal,
            provider: $provider,
            model: $model,
            timeout: $timeout,
            runId: $run->id,
            temperature: $options->temperature,
            maxTokens: $options->maxTokens,
            maxSteps: $options->maxSteps,
            topP: $options->topP,
        );
    }

    /**
     * Serialize resolved attachments for the queue. laravel/ai's File::toArray() ⇄
     * File::fromArray() is symmetric, so disk-backed / base64 / remote files travel
     * fine. A `local-*` file is a transient local path a worker can't read — reject
     * it at dispatch rather than failing cryptically on the worker.
     *
     * @param  array<int, File>  $files
     * @return array<int, array<string, mixed>>
     */
    protected function serializeAttachments(array $files): array
    {
        return array_map(static function (File $file): array {
            if (! $file instanceof Arrayable) {
                throw new RuntimeException('AiGenerateAction ->queued() attachments must be serializable (Arrayable). Got: '.$file::class);
            }

            /** @var array<string, mixed> $data */
            $data = $file->toArray();

            if (str_starts_with((string) ($data['type'] ?? ''), 'local-')) {
                throw new RuntimeException('AiGenerateAction ->queued() attachments must be disk-backed (Storage) or base64; a local filesystem path is not reachable from a worker. Got: '.$data['type']);
            }

            return $data;
        }, $files);
    }

    /**
     * Minimal per-row descriptor the worker needs to match + write back (spec 30 §5.3):
     * updateRecords carries just the pk; create/from-source carries the positional snapshot.
     *
     * @param  array<int, array<string, mixed>|Model>  $chunk
     * @return array<int, array<string, mixed>>
     */
    protected function buildChunkDescriptors(array $chunk): array
    {
        $identifierKey = $this->resolveIdentifierKey();

        if ($identifierKey !== '_index') {
            return array_map(static fn ($row): array => [$identifierKey => $row->getKey()], $chunk);
        }

        return array_map(static fn ($row): array => $row instanceof Model ? $row->toArray() : $row, array_values($chunk));
    }

    protected function sendQueuedStartedNotification(): void
    {
        Notification::make()
            ->title(filament_solaris_trans('notifications.batch_queued'))
            ->success()
            ->send();
    }
}
