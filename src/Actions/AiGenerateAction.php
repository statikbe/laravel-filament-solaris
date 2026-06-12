<?php

namespace Statikbe\FilamentSolaris\Actions;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Files\File;
use Laravel\Ai\Responses\StructuredAgentResponse;
use LogicException;
use RuntimeException;
use Statikbe\FilamentSolaris\Agents\SolarisAgent;
use Statikbe\FilamentSolaris\Concerns\HasGenerationOptions;
use Statikbe\FilamentSolaris\Concerns\HasUserInput;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Events\SolarisBatchCompleted;
use Statikbe\FilamentSolaris\Events\SolarisBatchStarted;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Statikbe\FilamentSolaris\Jobs\FinalizeRun;
use Statikbe\FilamentSolaris\Jobs\ProcessChunkJob;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;
use Statikbe\FilamentSolaris\Support\Batch\BatchGenerationException;
use Statikbe\FilamentSolaris\Support\Batch\BatchProcessor;
use Statikbe\FilamentSolaris\Support\Batch\BatchResponse;
use Statikbe\FilamentSolaris\Support\Batch\BatchRunConfig;
use Statikbe\FilamentSolaris\Support\Batch\FailedRecord;
use Statikbe\FilamentSolaris\Support\Batch\Runners\QueuedRunner;
use Statikbe\FilamentSolaris\Support\Batch\Sinks\CompositeBatchSink;
use Statikbe\FilamentSolaris\Support\Batch\Sinks\DatabaseBatchSink;
use Statikbe\FilamentSolaris\Support\Batch\Sinks\InMemoryBatchSink;
use Statikbe\FilamentSolaris\Support\ModelSchemaResolver;
use Statikbe\FilamentSolaris\Testing\AiGenerateActionFake;

/**
 * Form-agnostic AI action: generates structured data against a schema you
 * control (custom closure or model-derived) and hands the parsed result to
 * your {@see handleUsing()} closure — instead of writing into form fields.
 *
 * The sibling of {@see AiFormAction}: AiFormAction writes the AI output into a
 * form; AiGenerateAction gives it to you (seeders, table actions, info-gathering).
 */
class AiGenerateAction extends SolarisAction
{
    use HasGenerationOptions;
    use HasUserInput;

    public const RECORDS_KEY = BatchResponse::RECORDS;

    public const FAILED_KEY = BatchResponse::FAILED;

    public const WRITE_CREATE = 'create';

    public const WRITE_UPDATE = 'update';

    protected string|View|Closure|null $instruction = null;

    protected ?Closure $schemaResolver = null;

    /** @var class-string|null */
    protected ?string $modelClass = null;

    protected int|Closure $recordCount = 1;

    /** @var array<string> */
    protected array $onlyColumns = [];

    /** @var array<string> */
    protected array $exceptColumns = [];

    /** @var array<string, string> */
    protected array $columnHints = [];

    /** @var array<string, array<int, mixed>> */
    protected array $columnEnums = [];

    protected ?Closure $handler = null;

    protected ?Closure $onPartialFailure = null;

    /** @var Builder<Model>|Collection<int, array<string, mixed>>|EloquentCollection<int, Model>|array<int, array<string, mixed>|Model>|Closure|null */
    protected Builder|Collection|EloquentCollection|array|Closure|null $source = null;

    protected ?string $writeTerminal = null;

    protected int $writeTerminalCount = 0;

    /** @var array<string> */
    protected array $promptContextColumns = [];

    protected int|Closure $batchSize = 10;

    protected bool|Closure|null $tracked = null;

    protected bool|Closure $queued = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->icon(FilamentSolaris::config()->getActionIcon());

        $this->schema(fn (AiGenerateAction $action): array => $action->getUserInputFormSchema());

        $this->action(function (AiGenerateAction $action, array $data = []): void {
            $action->execute($data);
        });
    }

    public function prompt(string|View|Closure $instruction): static
    {
        $this->instruction = $instruction;

        return $this;
    }

    /**
     * @param  Closure(JsonSchemaTypeFactory): array<string, Type>  $schema
     */
    public function outputSchema(Closure $schema): static
    {
        $this->schemaResolver = $schema;

        return $this;
    }

    /**
     * @param  class-string  $modelClass
     */
    public function forModel(string $modelClass): static
    {
        $this->modelClass = $modelClass;

        return $this;
    }

    public function count(int|Closure $count): static
    {
        $this->recordCount = $count;

        return $this;
    }

    /**
     * @param  array<string>  $columns
     */
    public function only(array $columns): static
    {
        $this->onlyColumns = $columns;

        return $this;
    }

    /**
     * @param  array<string>  $columns
     */
    public function except(array $columns): static
    {
        $this->exceptColumns = $columns;

        return $this;
    }

    /**
     * (forModel only) attach a free-text hint to a column — surfaces as the
     * JSON-schema `description` so the model gets editorial guidance
     * ("conversational tone", "max 160 chars", …). No-op for a column not in
     * the resolved schema.
     */
    public function columnHint(string $column, string $hint): static
    {
        $this->columnHints[$column] = $hint;

        return $this;
    }

    /**
     * (forModel only) constrain a column to a fixed set of values. Overrides
     * cast-detected enums when both apply. No-op for a column not in the schema.
     *
     * @param  array<int, mixed>  $values
     */
    public function columnEnum(string $column, array $values): static
    {
        $this->columnEnums[$column] = $values;

        return $this;
    }

    /**
     * @param  Closure  $handler  receives `$data` (custom-schema mode → raw `array`; forModel mode → a `BatchResponse` with `->records` / `->failed`), `$userInput`, and Filament's standard DI.
     */
    public function handleUsing(Closure $handler): static
    {
        $this->handler = $handler;

        return $this;
    }

    /**
     * @param  Builder<Model>|Collection<int, array<string, mixed>>|EloquentCollection<int, Model>|array<int, array<string, mixed>|Model>|Closure  $source
     */
    public function sourceRecords(Builder|Collection|EloquentCollection|array|Closure $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function createRecords(): static
    {
        $this->writeTerminal = self::WRITE_CREATE;
        $this->writeTerminalCount++;

        return $this;
    }

    public function updateRecords(): static
    {
        $this->writeTerminal = self::WRITE_UPDATE;
        $this->writeTerminalCount++;

        return $this;
    }

    /**
     * Whitelist of column names sent into the `## Records` context block.
     * Default = all the row's attributes (auto-exclusions aside).
     *
     * @param  array<string>  $columns
     */
    public function promptContextColumns(array $columns): static
    {
        $this->promptContextColumns = $columns;

        return $this;
    }

    /**
     * Set the number of source rows per AI call when the records loop fires.
     * Default 10. A value of 1 still uses the batched code path with batches of one.
     *
     * Only applies with ->sourceRecords(); it has no effect on seed-from-scratch
     * or single-call actions (use ->count() for the seed-from-scratch size).
     */
    public function batchSize(int|Closure $size): static
    {
        $this->batchSize = $size;

        return $this;
    }

    /**
     * Persist this run (a solaris_batch_runs row + its problems) and fire
     * SolarisBatchStarted/Completed. Defaults to config batch_tracking.enabled.
     */
    public function trackBatchRuns(bool|Closure $tracked = true): static
    {
        $this->tracked = $tracked;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $userInput
     */
    protected function isTracked(array $userInput): bool
    {
        if ($this->tracked === null) {
            return FilamentSolaris::config()->isBatchTrackingEnabled();
        }

        return (bool) $this->evaluate($this->tracked, ['userInput' => $userInput]);
    }

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
     * Register a callback invoked when a batched run finishes with one or more
     * failed records (AI-reported failures, silent drops, unmatched
     * identifiers' rows, write errors, or whole-batch AI errors).
     *
     * The callback receives these named arguments (plus Filament's standard
     * injections like $livewire and $record):
     *   - array<int, FailedRecord> $failures — each with ->identifier, ->reason, ->input
     *   - int $succeeded — rows written successfully
     *   - int $failed — count($failures)
     *   - int $total — $succeeded + $failed
     *   - array<string, mixed> $userInput — the resolved user-input values
     */
    public function onPartialFailure(Closure $callback): static
    {
        $this->onPartialFailure = $callback;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $userInput
     */
    public function execute(array $userInput = []): void
    {
        $this->validateConfiguration();
        $this->guardClosureArgs();

        if ($this->source !== null) {
            $this->executeRecordsLoop($userInput);

            return;
        }

        if ($this->isQueued($userInput) && $this->writeTerminal !== null) {
            $this->dispatchQueuedSingleCall($userInput);

            return;
        }

        if (AiGenerateActionFake::isActive()) {
            $this->executeFake($userInput);

            return;
        }

        $instruction = $this->resolveInstruction($userInput);
        $resolver = $this->resolveSchemaResolver();

        ['provider' => $provider, 'model' => $model] = $this->resolveProviderAndModel();
        $timeout = $this->resolveTimeout();

        $agent = (new SolarisAgent)->configure($instruction, [], $resolver);
        $this->applyGenerationOptions($agent);

        $attachments = $this->resolveAttachments($userInput);

        /** @var StructuredAgentResponse|null $response */
        $response = $this->executeAiCall(
            fn () => $agent->prompt($instruction, $attachments, $provider, $model, $timeout),
            $provider,
            $model,
        );

        if ($response === null) {
            return;
        }

        $this->handleSingleCallResponse($response->toArray(), $userInput);
    }

    /**
     * @param  array<string, mixed>  $userInput
     */
    protected function executeFake(array $userInput = []): void
    {
        $fake = AiGenerateActionFake::getInstance();
        $data = $fake->getResponse();
        $attachments = $this->resolveAttachments($userInput);
        $fake->recordCall($this->getName(), $data, $userInput, $attachments);

        ['provider' => $provider, 'model' => $model] = $this->resolveProviderAndModel();

        if ($fake->shouldSimulateError()) {
            $this->dispatchFakeResponseFailed($fake->getErrorMessage(), $provider, $model);
            Notification::make()->title($fake->getErrorMessage())->danger()->send();

            return;
        }

        $this->dispatchFakeResponseReceived($provider, $model);
        $this->handleSingleCallResponse($data, $userInput);
    }

    /**
     * Handle the response from a single (non-loop) AI call. In forModel mode the
     * payload is parsed as a BatchResponse for unified handling; failures are
     * surfaced via the batch summary. In custom-outputSchema mode the raw assoc
     * array is handed to the user handler unchanged.
     *
     * @param  array<string, mixed>  $responseData
     * @param  array<string, mixed>  $userInput
     */
    protected function handleSingleCallResponse(array $responseData, array $userInput = []): void
    {
        try {
            if ($this->modelClass !== null) {
                $batchResponse = BatchResponse::fromArray($responseData);
                $identifierKey = $this->resolveIdentifierKey();

                if ($this->writeTerminal === self::WRITE_CREATE) {
                    $succeeded = 0;
                    $failures = $batchResponse->failed;

                    foreach ($batchResponse->records as $index => $record) {
                        $attrs = $record;
                        unset($attrs[$identifierKey]);

                        try {
                            $this->writeRow($record, $attrs);
                            $succeeded++;
                        } catch (\Throwable $e) {
                            // Expected per-row data failure — captured below, not report()ed.
                            $failures[] = new FailedRecord(
                                identifier: $record[$identifierKey] ?? $index,
                                reason: 'write error: '.$e->getMessage(),
                                input: $record,
                            );
                        }
                    }

                    $this->finishBatchRun($succeeded, $failures, $userInput);

                    return;
                }

                // handler mode in forModel: hand over a BatchResponse with the
                // synthetic identifier key stripped from each record, so handlers
                // never see the echoed _index / primary key.
                $this->runHandler($this->stripIdentifierKey($batchResponse, $identifierKey), $userInput);

                return;
            }

            // custom outputSchema mode: raw assoc, unchanged.
            $this->runHandler($responseData, $userInput);
        } catch (\Throwable $e) {
            report($e);
            Notification::make()
                ->title(filament_solaris_trans('notifications.handler_error'))
                ->danger()
                ->send();
        }
    }

    /**
     * Invoke the user handler with the resolved payload, recording the exact
     * value it received on the fake (so assertHandledWith reflects reality).
     *
     * @param  array<string, mixed>  $userInput
     */
    protected function runHandler(mixed $payload, array $userInput): void
    {
        if (AiGenerateActionFake::isActive()) {
            AiGenerateActionFake::getInstance()->recordHandlerCall($payload);
        }

        $this->evaluate($this->handler, [
            'data' => $payload,
            'userInput' => $userInput,
        ]);
    }

    /**
     * @param  array<string, mixed>  $userInput
     */
    protected function resolveInstruction(array $userInput = []): string
    {
        $instruction = $this->instruction;

        if ($instruction instanceof Closure) {
            $instruction = $this->evaluate($instruction, ['userInput' => $userInput]);
        }

        if ($instruction instanceof View) {
            $instruction = $instruction->render();
        }

        $instruction = (string) $instruction;

        if ($this->modelClass !== null) {
            $count = (int) $this->evaluate($this->recordCount, ['userInput' => $userInput]);
            $instruction = trim($instruction."\n\nGenerate {$count} records.");
        }

        $instruction = $this->appendUserContext($instruction, $userInput);

        if ($this->modelClass !== null) {
            $instruction = $this->appendSingleCallInstructions($instruction);
        }

        return $instruction;
    }

    /**
     * Append a `## User context` JSON block to the instruction when the
     * user-input modal yielded any filled values. No-op for empty input.
     *
     * @param  array<string, mixed>  $userInput
     */
    protected function appendUserContext(string $instruction, array $userInput): string
    {
        $filtered = array_filter($userInput, static fn ($v): bool => filled($v));

        if ($filtered === []) {
            return $instruction;
        }

        $json = json_encode($filtered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return trim($instruction)."\n\n## User context\n```json\n{$json}\n```";
    }

    /**
     * @param  array<string, mixed>  $userInput
     */
    protected function resolveBatchSize(array $userInput): int
    {
        $size = $this->batchSize instanceof Closure
            ? $this->evaluate($this->batchSize, ['userInput' => $userInput])
            : $this->batchSize;

        $size = (int) $size;

        if ($size <= 0) {
            throw new RuntimeException('AiGenerateAction ->batchSize() must resolve to a positive integer; got '.$size.'.');
        }

        return $size;
    }

    /**
     * @return Closure(JsonSchemaTypeFactory): array<string, Type>
     */
    protected function resolveSchemaResolver(): Closure
    {
        if ($this->schemaResolver !== null) {
            return $this->schemaResolver;
        }

        // validateConfiguration() guarantees a model when no outputSchema is set.
        assert($this->modelClass !== null);

        $identifierKey = $this->resolveIdentifierKey();

        return function (JsonSchemaTypeFactory $schema) use ($identifierKey): array {
            $properties = (new ModelSchemaResolver)->resolve(
                $schema,
                $this->modelClass,
                $this->onlyColumns,
                $this->exceptColumns,
                $this->columnHints,
                $this->columnEnums,
            );

            $properties[$identifierKey] = $identifierKey === '_index'
                ? $schema->integer()->description('The _index field from the input record. Echo unchanged.')
                : $schema->integer()->description('The primary key. Echo unchanged.');

            return [
                self::RECORDS_KEY => $schema->array()->items($schema->object($properties)),
                self::FAILED_KEY => $schema->array()->items($schema->object([
                    'identifier' => $schema->string()->description('Identifier of the failed input row (or freeform description in single-call mode).'),
                    'reason' => $schema->string()->description('Short reason for the failure (max 200 chars).'),
                ])),
            ];
        };
    }

    /**
     * Identifier key for the records-loop and single-call createRecords paths.
     * - updateRecords: the model's primary key column name (validated upstream that source rows are Models).
     * - createRecords (with or without source): `_index`.
     */
    protected function resolveIdentifierKey(): string
    {
        if ($this->writeTerminal === self::WRITE_UPDATE) {
            assert($this->modelClass !== null);

            return (new ($this->modelClass)())->getKeyName();
        }

        return '_index';
    }

    private function validateConfiguration(): void
    {
        $hasSchema = $this->schemaResolver !== null;
        $hasModel = $this->modelClass !== null;

        if ($hasSchema && $hasModel) {
            throw new RuntimeException('AiGenerateAction: use either ->outputSchema() or ->forModel(), not both.');
        }

        if (! $hasSchema && ! $hasModel) {
            throw new RuntimeException('AiGenerateAction requires a schema source: ->outputSchema() or ->forModel().');
        }

        // Terminals are mutually exclusive: exactly one must be configured.
        $hasHandler = $this->handler !== null;
        $hasWriteTerminal = $this->writeTerminalCount > 0;

        if (! $hasHandler && ! $hasWriteTerminal) {
            throw new RuntimeException('AiGenerateAction requires a terminal: ->handleUsing(), ->createRecords(), or ->updateRecords().');
        }

        if (($hasHandler && $hasWriteTerminal) || $this->writeTerminalCount > 1) {
            throw new RuntimeException('AiGenerateAction terminals are mutually exclusive: pick one of ->handleUsing(), ->createRecords(), ->updateRecords().');
        }

        // The records loop only runs write terminals; ->handleUsing() is never
        // invoked per batch. Catch the natural-but-unsupported combination early.
        if ($hasHandler && $this->source !== null) {
            throw new RuntimeException('AiGenerateAction ->sourceRecords() requires ->createRecords() or ->updateRecords(); ->handleUsing() does not run per batch.');
        }

        // createRecords/updateRecords need forModel (no custom schema for write-back).
        if ($this->writeTerminal !== null && ! $hasModel) {
            throw new RuntimeException('AiGenerateAction ->createRecords()/->updateRecords() require ->forModel().');
        }

        // updateRecords needs a source — without records() there is nothing to update.
        if ($this->writeTerminal === self::WRITE_UPDATE && $this->source === null) {
            throw new RuntimeException('AiGenerateAction ->updateRecords() requires ->sourceRecords() — without a source there is nothing to update.');
        }

        // count() drives the seed-from-scratch array size; with a real source,
        // the source defines the iteration count, so count() is meaningless.
        // recordCount defaults to 1; treat any non-1 with source set as misuse.
        if ($this->source !== null && (int) $this->evaluate($this->recordCount) !== 1) {
            throw new RuntimeException('AiGenerateAction ->count() is incompatible with ->sourceRecords() — the source defines how many rows to process.');
        }

        // Queued guards — only validate when the flag is statically true.
        // A Closure-gated queued flag is deferred to execution time.
        $queued = $this->queued === true;

        if ($queued && $this->writeTerminal === null) {
            throw new RuntimeException('AiGenerateAction ->queued() requires ->createRecords() or ->updateRecords(); handler-mode and custom ->outputSchema() run a closure that cannot be queued.');
        }
    }

    /**
     * Throws if any dev-supplied closure declares `$row` (singular).
     * Spec 27 removed the per-row code path; closures now always receive `$rows` (plural).
     */
    protected function guardClosureArgs(): void
    {
        $closures = array_filter([
            $this->instruction instanceof Closure ? $this->instruction : null,
            $this->handler,
            $this->source instanceof Closure ? $this->source : null,
        ]);

        foreach ($closures as $closure) {
            $reflection = new \ReflectionFunction($closure);
            foreach ($reflection->getParameters() as $param) {
                if ($param->getName() === 'row') {
                    throw new LogicException(
                        'AiGenerateAction closures must declare `$rows` (plural), not `$row`. '.
                        'The single-row code path was removed in spec 27; even at batchSize=1, '.
                        'closures receive a batch (array of rows). See documentation/ai-generate-action.md#batching.'
                    );
                }
            }
        }
    }

    /**
     * Preview/conversational are unsupported — unreachable guards required by SolarisAction.
     *
     * @param  array<string, mixed>  $data
     */
    public function acceptPreview(array $data): void
    {
        throw new LogicException('AiGenerateAction does not support the preview modal.');
    }

    /**
     * @param  array<string, mixed>  $turnAttachments
     */
    public function refine(string $message, array $turnAttachments = []): void
    {
        throw new LogicException('AiGenerateAction does not support conversational refinement.');
    }

    // ── Records loop ─────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $userInput
     */
    protected function executeRecordsLoop(array $userInput = []): void
    {
        $rows = $this->resolveRecordsSource($userInput);
        ['provider' => $provider, 'model' => $model] = $this->resolveProviderAndModel();
        $timeout = $this->resolveTimeout();
        $batchSize = $this->resolveBatchSize($userInput);

        if ($this->isQueued($userInput)) {
            $this->dispatchQueuedRun($rows, $userInput, $provider, $model, $timeout, $batchSize);

            return;
        }

        $resolver = $this->resolveSchemaResolver();
        $attachments = $this->resolveAttachments($userInput);

        $collector = new InMemoryBatchSink;

        $run = $this->isTracked($userInput) ? $this->startBatchRun($rows) : null;
        $sink = $run === null
            ? $collector
            : new CompositeBatchSink([$collector, new DatabaseBatchSink($run->id)]);

        $processor = new BatchProcessor(
            $this->resolveIdentifierKey(),
            $this->makeResponseGenerator($userInput, $attachments, $provider, $model, $timeout, $resolver),
            fn (mixed $source, array $attributes) => $this->writeRow($source, $attributes),
            $sink,
        );

        $processor->process($rows, $batchSize);

        foreach ($collector->discarded() as $discarded) {
            $this->logToFailureChannel($discarded->reason);
        }

        $this->finishBatchRun($collector->succeeded(), $collector->failures(), $userInput);

        if ($run !== null) {
            $run->markCompleted();
            SolarisBatchCompleted::dispatch(
                $run->id,
                $this->getName(),
                $collector->succeeded(),
                count($collector->failures()),
                count($collector->discarded()),
                BatchRunStatus::Completed,
            );
        }
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
     * @param  array<string, mixed>  $userInput
     * @param  iterable<int, array<string, mixed>|Model>  $rows
     */
    protected function dispatchQueuedRun(iterable $rows, array $userInput, mixed $provider, ?string $model, ?int $timeout, int $batchSize): void
    {
        $run = $this->startBatchRun($rows);
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

        $run = $this->startBatchRun(null);   // total unknown until the model answers
        $config = $this->buildRunConfig($run, $provider, $model, $timeout);

        $attachments = $this->serializeAttachments($this->resolveAttachments($userInput));

        $runId = $run->id;
        $actionName = $this->getName();

        Bus::batch([
            new ProcessChunkJob(
                config: $config,
                prompt: $instruction,
                rowDescriptors: [],
                attachments: $attachments,
            ),
        ])
            ->name('solaris:'.$runId)
            ->allowFailures()
            ->finally(function (Batch $batch) use ($runId, $actionName): void {
                FinalizeRun::dispatch($runId, $actionName, $batch->cancelled());
            })
            ->dispatch();

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

    /**
     * @param  iterable<int, array<string, mixed>|Model>  $rows
     */
    protected function startBatchRun(?iterable $rows): SolarisBatchRun
    {
        $livewire = $this->getLivewire();

        $run = SolarisBatchRun::create([
            'action_name' => $this->getName(),
            'user_id' => ($userId = auth()->id()) === null ? null : (string) $userId,
            'page' => $livewire !== null ? $livewire::class : null,
            'status' => BatchRunStatus::Processing,
            // null for the single-call path: the row count is unknown until the model answers.
            'total' => $rows !== null && is_countable($rows) ? count($rows) : null,
            'started_at' => now(),
        ]);

        SolarisBatchStarted::dispatch($run->id, $run->action_name, $run->user_id, $run->page, $run->total);

        return $run;
    }

    /**
     * Build the per-batch AI-call closure for the processor. Real path calls the
     * agent synchronously; fake path replays the canned response. Either throws
     * BatchGenerationException on an AI-call failure so the processor marks the
     * batch failed. This is where the old processBatch/processFakeBatch split lived.
     *
     * @param  array<string, mixed>  $userInput
     * @param  array<int, File>  $attachments
     * @param  Closure(JsonSchemaTypeFactory): array<string, Type>  $resolver
     * @return Closure(array<int, array<string, mixed>|Model>): BatchResponse
     */
    protected function makeResponseGenerator(array $userInput, array $attachments, mixed $provider, ?string $model, ?int $timeout, Closure $resolver): Closure
    {
        if (AiGenerateActionFake::isActive()) {
            return function (array $batch) use ($userInput, $attachments, $provider, $model): BatchResponse {
                // Resolve the instruction so prompt-closure errors still surface under the fake.
                $this->buildBatchInstruction($batch, $userInput);

                $fake = AiGenerateActionFake::getInstance();
                $rawResponse = $fake->getResponse();

                [, $rows] = $this->enrichBatchWithIdentifier($batch);
                $fake->recordCall($this->getName(), $rawResponse, $userInput, $attachments, $rows);

                if ($fake->shouldSimulateError()) {
                    $this->dispatchFakeResponseFailed($fake->getErrorMessage(), $provider, $model);

                    throw new BatchGenerationException($fake->getErrorMessage());
                }

                $this->dispatchFakeResponseReceived($provider, $model);

                return BatchResponse::fromArray($rawResponse);
            };
        }

        return function (array $batch) use ($userInput, $attachments, $provider, $model, $timeout, $resolver): BatchResponse {
            $instruction = $this->buildBatchInstruction($batch, $userInput);
            $agent = (new SolarisAgent)->configure($instruction, [], $resolver);
            $this->applyGenerationOptions($agent);

            /** @var StructuredAgentResponse|null $response */
            $response = $this->executeAiCall(
                fn () => $agent->prompt($instruction, $attachments, $provider, $model, $timeout),
                $provider,
                $model,
                static fn (): null => null,
            );

            if ($response === null) {
                throw new BatchGenerationException('AI call error');
            }

            return BatchResponse::fromArray($response->toArray());
        };
    }

    /**
     * Close out a batched run: log + surface any failures, then notify.
     *
     * @param  array<int, FailedRecord>  $failures
     * @param  array<string, mixed>  $userInput
     */
    protected function finishBatchRun(int $succeeded, array $failures, array $userInput): void
    {
        $failed = count($failures);

        if ($failures !== []) {
            $this->reportFailures($failures);

            if ($this->onPartialFailure !== null) {
                // A throwing callback must not abort the run after rows are
                // already written, nor swallow the summary notification.
                try {
                    $this->evaluate($this->onPartialFailure, [
                        'failures' => $failures,
                        'succeeded' => $succeeded,
                        'failed' => $failed,
                        'total' => $succeeded + $failed,
                        'userInput' => $userInput,
                    ]);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        $this->sendBatchSummary($succeeded, $failed);
    }

    /**
     * Log the failure manifest so failures are never silently dropped, even when
     * no ->onPartialFailure() callback is registered. Models are reduced to their
     * key to keep the log readable.
     *
     * @param  array<int, FailedRecord>  $failures
     */
    protected function reportFailures(array $failures): void
    {
        $this->logToFailureChannel(
            'AiGenerateAction: '.count($failures).' record(s) failed during a batched run.',
            [
                'action' => $this->getName(),
                'failures' => array_map(fn (FailedRecord $f): array => [
                    'identifier' => $f->identifier,
                    'reason' => $f->reason,
                    'input' => $f->input instanceof Model ? $f->input->getKey() : $f->input,
                ], $failures),
            ],
        );
    }

    /**
     * Log a batch diagnostic on the failure-logging channel (gated by
     * `failure_logging.enabled`). Used for the aggregated manifest and for
     * reconcile anomalies (unmatched / duplicate identifiers) — none of which
     * are bugs, so they go here rather than to `report()`.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logToFailureChannel(string $message, array $context = []): void
    {
        $config = FilamentSolaris::config();

        if (! $config->isFailureLoggingEnabled()) {
            return;
        }

        $channel = $config->getFailureLoggingChannel();

        if ($channel !== null) {
            Log::channel($channel)->warning($message, $context);

            return;
        }

        Log::warning($message, $context);
    }

    /**
     * @param  array<string, mixed>  $userInput
     * @return iterable<int, array<string, mixed>|Model>
     */
    protected function resolveRecordsSource(array $userInput = []): iterable
    {
        $source = $this->source instanceof Closure
            ? $this->evaluate($this->source, ['userInput' => $userInput])
            : $this->source;

        if ($source instanceof Builder) {
            return $source->get();
        }

        if ($source instanceof EloquentCollection || $source instanceof Collection) {
            return $source;
        }

        if (is_array($source)) {
            return $source;
        }

        throw new RuntimeException('AiGenerateAction ->sourceRecords() must yield a Builder, Collection, or array; got '.get_debug_type($source));
    }

    /**
     * @param  array<string, mixed>|Model  $row
     * @param  array<string, mixed>  $attrs
     */
    protected function writeRow(array|Model $row, array $attrs): void
    {
        if ($this->writeTerminal === self::WRITE_CREATE) {
            $this->modelClass::create($attrs);

            return;
        }

        // WRITE_UPDATE
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

    /**
     * Copy of a BatchResponse with the synthetic identifier key removed from
     * every record — so single-call handler-mode consumers never receive the
     * echoed `_index` / primary key in their `$data->records`.
     */
    protected function stripIdentifierKey(BatchResponse $response, string $identifierKey): BatchResponse
    {
        $records = array_map(static function (array $record) use ($identifierKey): array {
            unset($record[$identifierKey]);

            return $record;
        }, $response->records);

        return new BatchResponse($records, $response->failed);
    }

    /**
     * @param  array<string, mixed>|Model  $row
     * @return array<string, mixed>
     */
    protected function buildContextForRow(array|Model $row): array
    {
        $attrs = $row instanceof Model ? $row->getAttributes() : $row;

        if ($row instanceof Model) {
            $excluded = (new ModelSchemaResolver)->autoExcludedColumns($row);
            $attrs = array_diff_key($attrs, array_flip($excluded));
        }

        if ($this->promptContextColumns !== []) {
            $attrs = array_intersect_key($attrs, array_flip($this->promptContextColumns));
        }

        return $attrs;
    }

    /**
     * @param  array<int, array<string, mixed>|Model>  $batch
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    protected function enrichBatchWithIdentifier(array $batch): array
    {
        $identifierKey = $this->resolveIdentifierKey();

        if ($identifierKey !== '_index') {
            // updateRecords: PK echo. Source rows are always Models (validated upstream).
            $rows = array_map(function ($row) use ($identifierKey): array {
                assert($row instanceof Model);
                $attrs = $this->buildContextForRow($row);
                $attrs[$identifierKey] = $row->getKey();

                return $attrs;
            }, $batch);

            return [$identifierKey, $rows];
        }

        $rows = [];
        foreach ($batch as $index => $row) {
            $attrs = $this->buildContextForRow($row);
            $attrs[$identifierKey] = $index;
            $rows[] = $attrs;
        }

        return [$identifierKey, $rows];
    }

    /**
     * @param  array<int, array<string, mixed>|Model>  $batch
     */
    protected function appendRecordsBlock(string $instruction, array $batch): string
    {
        [, $rows] = $this->enrichBatchWithIdentifier($batch);

        $json = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return trim($instruction)."\n\n## Records\n```json\n{$json}\n```";
    }

    protected function appendBatchInstructions(string $instruction): string
    {
        $identifierKey = $this->resolveIdentifierKey();

        $boilerplate = <<<TXT
## Instructions
For each record above, return an entry in `records` echoing the `{$identifierKey}` field unchanged with the processed fields.
For any record you cannot process, add an entry to `failed` with the `identifier` set to the `{$identifierKey}` value and a short `reason` (max 200 chars).
Preserve input order in the `records` array.
TXT;

        return trim($instruction)."\n\n".$boilerplate;
    }

    /**
     * @param  array<int, array<string, mixed>|Model>  $batch
     * @param  array<string, mixed>  $userInput
     */
    protected function buildBatchInstruction(array $batch, array $userInput): string
    {
        $instruction = $this->instruction;

        if ($instruction instanceof Closure) {
            // Same filtered view the AI gets in the ## Records block
            // (promptContextColumns + auto-exclusions), without the synthetic
            // identifier key — so a closure echoing $rows can't leak columns the
            // dev deliberately withheld via ->promptContextColumns().
            $rows = array_map(fn ($row): array => $this->buildContextForRow($row), $batch);
            $instruction = $this->evaluate($instruction, [
                'rows' => $rows,
                'userInput' => $userInput,
            ]);
        }

        if ($instruction instanceof View) {
            $instruction = $instruction->render();
        }

        $instruction = (string) $instruction;
        $instruction = $this->appendUserContext($instruction, $userInput);
        $instruction = $this->appendRecordsBlock($instruction, $batch);
        $instruction = $this->appendBatchInstructions($instruction);

        return $instruction;
    }

    protected function appendSingleCallInstructions(string $instruction): string
    {
        $boilerplate = <<<'TXT'
## Instructions
Return generated records in the `records` array.
For any input you cannot process (e.g., malformed line, ambiguous source data), add an entry to `failed` with an `identifier` describing the failed input (line number, source excerpt) and a short `reason`.
TXT;

        return trim($instruction)."\n\n".$boilerplate;
    }

    protected function sendBatchSummary(int $succeeded, int $failed): void
    {
        if ($failed === 0) {
            Notification::make()
                ->title(filament_solaris_trans('notifications.batch_completed', ['count' => $succeeded]))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(filament_solaris_trans('notifications.batch_partial_failure', [
                'count' => $succeeded,
                'failed' => $failed,
            ]))
            ->warning()
            ->send();
    }

    // ── Testing ──────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $response
     */
    public static function fake(array $response = []): AiGenerateActionFake
    {
        return AiGenerateActionFake::activate($response);
    }

    /**
     * @param  array<int, array<string, mixed>>  $responses
     */
    public static function fakeEach(array $responses): AiGenerateActionFake
    {
        return AiGenerateActionFake::fakeEach($responses);
    }

    public static function fakeError(string $message = 'AI service error'): AiGenerateActionFake
    {
        return AiGenerateActionFake::activateError($message);
    }

    public static function assertCalled(): void
    {
        AiGenerateActionFake::getInstance()->assertCalled();
    }

    public static function assertCalledTimes(int $count): void
    {
        AiGenerateActionFake::getInstance()->assertCalledTimes($count);
    }

    public static function assertNotCalled(): void
    {
        AiGenerateActionFake::getInstance()->assertNotCalled();
    }

    public static function assertHandledWith(Closure $callback): void
    {
        AiGenerateActionFake::getInstance()->assertHandledWith($callback);
    }

    public static function assertCalledWithUserInput(Closure $callback): void
    {
        AiGenerateActionFake::getInstance()->assertCalledWithUserInput($callback);
    }

    public static function assertCalledWithAttachments(Closure $callback): void
    {
        AiGenerateActionFake::getInstance()->assertCalledWithAttachments($callback);
    }

    public static function assertCalledWithBatch(Closure $callback): void
    {
        AiGenerateActionFake::getInstance()->assertCalledWithBatch($callback);
    }
}
