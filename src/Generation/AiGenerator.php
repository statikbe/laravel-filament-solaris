<?php

namespace Statikbe\FilamentSolaris\Generation;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Files\File;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Statikbe\FilamentSolaris\Agents\SolarisAgent;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Events\SolarisBatchCompleted;
use Statikbe\FilamentSolaris\Events\SolarisResponseFailed;
use Statikbe\FilamentSolaris\Events\SolarisResponseReceived;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;
use Statikbe\FilamentSolaris\Sanitizers\Sanitizer;
use Statikbe\FilamentSolaris\Sanitizers\SanitizerExecutor;
use Statikbe\FilamentSolaris\Support\Batch\BatchGenerationException;
use Statikbe\FilamentSolaris\Support\Batch\BatchProcessor;
use Statikbe\FilamentSolaris\Support\Batch\BatchPromptBuilder;
use Statikbe\FilamentSolaris\Support\Batch\BatchResponse;
use Statikbe\FilamentSolaris\Support\Batch\BatchRunConfig;
use Statikbe\FilamentSolaris\Support\Batch\BatchSummary;
use Statikbe\FilamentSolaris\Support\Batch\FailedRecord;
use Statikbe\FilamentSolaris\Support\Batch\RecordsSchemaBuilder;
use Statikbe\FilamentSolaris\Support\Batch\RecordWriter;
use Statikbe\FilamentSolaris\Support\Batch\Runners\InlineRunner;
use Statikbe\FilamentSolaris\Support\Batch\Runners\QueuedRunner;
use Statikbe\FilamentSolaris\Support\GenerationOptions;
use Statikbe\FilamentSolaris\Support\SolarisPromptLogger;

/**
 * Headless, Filament-free service that performs structured AI generation — a
 * single call ({@see GenerationResult}) or a records loop with write-back
 * ({@see BatchSummary}). Build it fluently and call {@see runInline()}.
 *
 * Usable from jobs, listeners, commands, and chainable (feed one result's
 * ->data into the next builder). Filament actions configure it from their own
 * resolved values; headless callers configure it directly.
 *
 * Unlike the action wrapper it throws on failure (single call → {@see AiException};
 * batch → per-batch {@see BatchGenerationException} captured in the summary)
 * instead of sending a UI notification — error presentation is the caller's concern.
 */
class AiGenerator
{
    protected string|View|Closure $prompt = '';

    /** @var ?Closure(JsonSchemaTypeFactory): array<string, Type> */
    protected ?Closure $schemaResolver = null;

    /** @var Lab|array<int|string, string>|string|null */
    protected Lab|array|string|null $provider = null;

    protected ?string $model = null;

    protected ?int $timeout = null;

    protected GenerationOptions $options;

    /** @var array<int, File> */
    protected array $attachments = [];

    /** @var ?iterable<mixed> */
    protected ?iterable $tools = null;

    protected string $sourceName = 'ai-generator';

    /** @var class-string */
    protected string $sourceClass = self::class;

    protected ?object $livewire = null;

    protected bool $hasUser = false;

    protected ?Authenticatable $user = null;

    // ── Batch (records loop) config ──────────────────────────────

    /** @var class-string|null */
    protected ?string $modelClass = null;

    /** @var array<string> */
    protected array $onlyColumns = [];

    /** @var array<string> */
    protected array $exceptColumns = [];

    /** @var array<string, string> */
    protected array $columnHints = [];

    /** @var array<string, array<int, mixed>> */
    protected array $columnEnums = [];

    /** @var iterable<int, array<string, mixed>|Model>|null */
    protected ?iterable $sourceRecords = null;

    protected ?string $writeTerminal = null;

    protected int $recordCount = 1;

    protected int $batchSize = 10;

    /** @var array<string> */
    protected array $promptContextColumns = [];

    /** @var array<string, mixed> */
    protected array $userInput = [];

    /** @var Closure|Sanitizer|array<int, Closure|Sanitizer>|null */
    protected Closure|Sanitizer|array|null $sanitizer = null;

    /** @var array<string, Closure|Sanitizer|array<int, Closure|Sanitizer>> */
    protected array $fieldSanitizers = [];

    protected bool $tracked = false;

    /** @var array<int, class-string> */
    protected array $completionHandlers = [];

    protected bool $attachFailureReport = true;

    /** @var ?Closure(array<int, array<string, mixed>|Model>): BatchResponse */
    protected ?Closure $responseGeneratorOverride = null;

    public function __construct()
    {
        $this->options = new GenerationOptions;
    }

    public static function make(): static
    {
        return new static;
    }

    public function prompt(string|View|Closure $prompt): static
    {
        $this->prompt = $prompt;

        return $this;
    }

    /**
     * @param  Closure(JsonSchemaTypeFactory): array<string, Type>  $resolver
     */
    public function schema(Closure $resolver): static
    {
        $this->schemaResolver = $resolver;

        return $this;
    }

    /**
     * @param  Lab|array<int|string, string>|string|null  $provider
     */
    public function provider(Lab|array|string|null $provider, ?string $model = null): static
    {
        $this->provider = $provider;
        $this->model = $model;

        return $this;
    }

    public function timeout(?int $timeout): static
    {
        $this->timeout = $timeout;

        return $this;
    }

    public function options(GenerationOptions $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * @param  array<int, File>  $attachments
     */
    public function attachments(array $attachments): static
    {
        $this->attachments = $attachments;

        return $this;
    }

    /**
     * @param  ?iterable<mixed>  $tools
     */
    public function tools(?iterable $tools): static
    {
        $this->tools = $tools;

        return $this;
    }

    /**
     * Event-source labels stamped onto the Solaris response/batch events and the
     * tracked run's action_name.
     *
     * @param  class-string|null  $sourceClass
     */
    public function eventSource(string $name, ?string $sourceClass = null): static
    {
        $this->sourceName = $name;
        $this->sourceClass = $sourceClass ?? $this->sourceClass;

        return $this;
    }

    public function forLivewire(?object $livewire): static
    {
        $this->livewire = $livewire;

        return $this;
    }

    public function forUser(?Authenticatable $user): static
    {
        $this->hasUser = true;
        $this->user = $user;

        return $this;
    }

    // ── Batch setters ────────────────────────────────────────────

    /**
     * @param  class-string  $modelClass
     */
    public function forModel(string $modelClass): static
    {
        $this->modelClass = $modelClass;

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
     * @param  array<string, string>  $hints  column => description text
     */
    public function columnHints(array $hints): static
    {
        $this->columnHints = $hints;

        return $this;
    }

    /**
     * @param  array<string, array<int, mixed>>  $enums  column => allowed values
     */
    public function columnEnums(array $enums): static
    {
        $this->columnEnums = $enums;

        return $this;
    }

    /**
     * Pre-resolved source rows for the records loop (a Builder is materialised).
     *
     * @param  Builder<Model>|Collection<int, array<string, mixed>>|EloquentCollection<int, Model>|iterable<int, array<string, mixed>|Model>  $rows
     */
    public function sourceRecords(iterable|Builder $rows): static
    {
        $this->sourceRecords = $rows instanceof Builder ? $rows->get() : $rows;

        return $this;
    }

    /**
     * Number of records to seed when generating from scratch (createRecords with
     * no ->sourceRecords()).
     */
    public function count(int $count): static
    {
        $this->recordCount = $count;

        return $this;
    }

    public function createRecords(): static
    {
        $this->writeTerminal = RecordWriter::CREATE;

        return $this;
    }

    public function updateRecords(): static
    {
        $this->writeTerminal = RecordWriter::UPDATE;

        return $this;
    }

    public function batchSize(int $size): static
    {
        $this->batchSize = $size;

        return $this;
    }

    /**
     * @param  array<string>  $columns
     */
    public function promptContextColumns(array $columns): static
    {
        $this->promptContextColumns = $columns;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $userInput
     */
    public function userInput(array $userInput): static
    {
        $this->userInput = $userInput;

        return $this;
    }

    /**
     * Default sanitizer applied to every generated string value before write-back.
     * A closure wraps in CallableSanitizer, an array in CompositeSanitizer (pipeline).
     *
     * @param  Closure|Sanitizer|array<int, Closure|Sanitizer>  $sanitizer
     */
    public function sanitize(Closure|Sanitizer|array $sanitizer): static
    {
        $this->sanitizer = $sanitizer;

        return $this;
    }

    /**
     * Per-field sanitizer override (by column name); wins over the ->sanitize() default.
     *
     * @param  Closure|Sanitizer|array<int, Closure|Sanitizer>  $sanitizer
     */
    public function sanitizeField(string $field, Closure|Sanitizer|array $sanitizer): static
    {
        $this->fieldSanitizers[$field] = $sanitizer;

        return $this;
    }

    protected function resolveSanitizers(): ?SanitizerExecutor
    {
        if ($this->sanitizer === null && $this->fieldSanitizers === []) {
            return null;
        }

        return SanitizerExecutor::make($this->sanitizer, $this->fieldSanitizers);
    }

    public function trackBatchRuns(bool $tracked = true): static
    {
        $this->tracked = $tracked;

        return $this;
    }

    /**
     * @param  array<int, class-string>  $handlers
     */
    public function onCompletion(array $handlers): static
    {
        $this->completionHandlers = array_values($handlers);

        return $this;
    }

    public function withFailureReport(bool $attach = true): static
    {
        $this->attachFailureReport = $attach;

        return $this;
    }

    /**
     * Override the per-batch "AI call → BatchResponse" step. The internal seam the
     * Filament action's fake plugs into; headless callers leave it unset to use the
     * real agent call.
     *
     * @param  Closure(array<int, array<string, mixed>|Model>): BatchResponse  $generator
     */
    public function responseGenerator(Closure $generator): static
    {
        $this->responseGeneratorOverride = $generator;

        return $this;
    }

    // ── Terminals ────────────────────────────────────────────────

    public function runInline(): GenerationResult|BatchSummary
    {
        if ($this->writeTerminal !== null) {
            return $this->runBatch();
        }

        return $this->runSingleStructuredCall();
    }

    /**
     * Dispatch the run to the queue (a Bus::batch of per-chunk jobs) and return the
     * persisted {@see SolarisBatchRun} handle — the outcome arrives later via events
     * + completion handlers. Always tracks (the run row aggregates per-chunk
     * outcomes). Records-loop vs from-scratch is inferred from ->sourceRecords().
     */
    public function runQueued(): SolarisBatchRun
    {
        if ($this->writeTerminal === null) {
            throw new RuntimeException('AiGenerator ->runQueued() requires a write terminal (->createRecords()/->updateRecords()); a closure/handler cannot be queued.');
        }

        if ($this->modelClass === null) {
            throw new RuntimeException('AiGenerator ->runQueued() requires ->forModel().');
        }

        if ($this->resolveSanitizers()?->isSerializable() === false) {
            throw new RuntimeException('AiGenerator ->runQueued() cannot serialise a closure sanitizer to the worker — use a Sanitizer class (e.g. StripTagsSanitizer) instead of a closure, or run inline.');
        }

        return $this->sourceRecords === null
            ? $this->dispatchQueuedFromScratch()
            : $this->dispatchQueuedRecordsLoop($this->sourceRecords);
    }

    /**
     * @param  iterable<int, array<string, mixed>|Model>  $rows
     */
    protected function dispatchQueuedRecordsLoop(iterable $rows): SolarisBatchRun
    {
        $run = $this->createTrackedRun(is_countable($rows) ? count($rows) : null);
        $config = $this->buildRunConfig($run);
        $attachments = $this->serializeAttachments($this->attachments);
        $promptBuilder = new BatchPromptBuilder($this->resolveIdentifierKey(), $this->promptContextColumns);

        (new QueuedRunner)->dispatch(
            run: $run,
            config: $config,
            chunks: BatchProcessor::chunkRows($rows, $this->batchSize),
            renderPrompt: fn (array $chunk): string => $promptBuilder->build($this->prompt, $chunk, $this->userInput),
            buildDescriptors: fn (array $chunk): array => $this->buildChunkDescriptors($chunk),
            attachments: $attachments,
        );

        return $run;
    }

    protected function dispatchQueuedFromScratch(): SolarisBatchRun
    {
        $instruction = BatchPromptBuilder::fromScratch($this->prompt, $this->recordCount, $this->userInput);

        $run = $this->createTrackedRun(null);   // total unknown until the model answers
        $config = $this->buildRunConfig($run);
        $attachments = $this->serializeAttachments($this->attachments);

        (new QueuedRunner)->dispatchSingleCall($run, $config, $instruction, $attachments);

        return $run;
    }

    protected function buildRunConfig(SolarisBatchRun $run): BatchRunConfig
    {
        return new BatchRunConfig(
            actionName: $this->sourceName,
            modelClass: $this->modelClass,
            onlyColumns: $this->onlyColumns,
            exceptColumns: $this->exceptColumns,
            columnHints: $this->columnHints,
            columnEnums: $this->columnEnums,
            identifierKey: $this->resolveIdentifierKey(),
            writeTerminal: $this->writeTerminal,
            provider: $this->provider,
            model: $this->model,
            timeout: $this->timeout,
            runId: $run->id,
            temperature: $this->options->temperature,
            maxTokens: $this->options->maxTokens,
            maxSteps: $this->options->maxSteps,
            topP: $this->options->topP,
            sanitizers: $this->resolveSanitizers(),
        );
    }

    /**
     * Serialize resolved attachments for the queue. File::toArray() ⇄ fromArray() is
     * symmetric, so disk-backed / base64 / remote files travel fine. A `local-*`
     * file is a transient local path a worker can't read — reject it at dispatch.
     *
     * @param  array<int, File>  $files
     * @return array<int, array<string, mixed>>
     */
    protected function serializeAttachments(array $files): array
    {
        return array_map(static function (File $file): array {
            if (! $file instanceof Arrayable) {
                throw new RuntimeException('AiGenerator ->runQueued() attachments must be serializable (Arrayable). Got: '.$file::class);
            }

            /** @var array<string, mixed> $data */
            $data = $file->toArray();

            if (str_starts_with((string) ($data['type'] ?? ''), 'local-')) {
                throw new RuntimeException('AiGenerator ->runQueued() attachments must be disk-backed (Storage) or base64; a local filesystem path is not reachable from a worker. Got: '.$data['type']);
            }

            return $data;
        }, $files);
    }

    /**
     * Minimal per-row descriptor the worker needs to match + write back:
     * updateRecords carries just the pk; create/from-source carries the snapshot.
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

    protected function runBatch(): BatchSummary
    {
        if ($this->modelClass === null) {
            throw new RuntimeException('AiGenerator ->createRecords()/->updateRecords() require ->forModel().');
        }

        if ($this->sourceRecords === null) {
            return $this->runFromScratch();
        }

        $rows = $this->sourceRecords;
        $modelClass = $this->modelClass;
        $writeTerminal = $this->writeTerminal;

        $run = $this->tracked
            ? $this->createTrackedRun(is_countable($rows) ? count($rows) : null)
            : null;

        return (new InlineRunner)->run(
            actionName: $this->sourceName,
            rows: $rows,
            batchSize: $this->batchSize,
            identifierKey: $this->resolveIdentifierKey(),
            generateResponse: $this->responseGeneratorOverride ?? $this->buildBatchResponseGenerator(),
            persistRecord: fn (mixed $source, array $attrs) => (new RecordWriter($modelClass, $writeTerminal, $this->resolveSanitizers()))->write($source, $attrs),
            run: $run,
            completionHandlers: $this->completionHandlers,
            userInput: $this->userInput,
        );
    }

    /**
     * Seed-from-scratch create: one structured call returning records[]/failed[],
     * write every returned record, capture per-row write errors, and finalize to a
     * BatchSummary. No reconciliation — there are no input rows to match against.
     */
    protected function runFromScratch(): BatchSummary
    {
        $modelClass = $this->modelClass;
        $identifierKey = $this->resolveIdentifierKey();
        $writer = new RecordWriter($modelClass, $this->writeTerminal, $this->resolveSanitizers());

        // total is unknown until the model answers (no input rows to count).
        $run = $this->tracked ? $this->createTrackedRun(null) : null;

        $generate = $this->responseGeneratorOverride ?? $this->buildFromScratchResponseGenerator();
        $response = $generate([]);

        $succeeded = 0;
        $failures = $response->failed;

        foreach ($response->records as $index => $record) {
            $attrs = $record;
            unset($attrs[$identifierKey]);

            try {
                $writer->write($record, $attrs);
                $succeeded++;
            } catch (\Throwable $e) {
                // Expected per-row data failure — captured, not report()ed.
                $failures[] = new FailedRecord(
                    identifier: $record[$identifierKey] ?? $index,
                    reason: 'write error: '.$e->getMessage(),
                    input: $record,
                );
            }
        }

        if ($run !== null) {
            $run->markCompleted();
            SolarisBatchCompleted::dispatch($run->id, $this->sourceName, $succeeded, count($failures), 0, BatchRunStatus::Completed);
        }

        return (new InlineRunner)->finalize(
            $this->sourceName,
            $succeeded,
            $failures,
            0,
            $this->userInput,
            $run,
            $this->completionHandlers,
        );
    }

    /**
     * Real from-scratch step: assemble the seed prompt, call the agent once, fire
     * events. Re-wraps an AiException as BatchGenerationException for symmetry with
     * the records-loop path.
     *
     * @return Closure(array<int, array<string, mixed>|Model>): BatchResponse
     */
    protected function buildFromScratchResponseGenerator(): Closure
    {
        $schemaResolver = $this->resolveBatchSchemaResolver();
        $count = $this->recordCount;
        $userInput = $this->userInput;

        return function (array $batch) use ($schemaResolver, $count, $userInput): BatchResponse {
            $instruction = BatchPromptBuilder::fromScratch($this->prompt, $count, $userInput);

            $agent = (new SolarisAgent)->configure($instruction, [], $schemaResolver);

            if ($this->tools !== null) {
                $agent->withTools($this->tools);
            }

            $this->options->applyTo($agent);

            try {
                $response = $this->callAgent($agent, $instruction);
            } catch (AiException $e) {
                throw new BatchGenerationException($e->getMessage(), (int) $e->getCode(), $e);
            }

            return BatchResponse::fromArray($response->toArray());
        };
    }

    /**
     * Real per-batch step: assemble the prompt, call the agent, fire events, and
     * normalise failures to BatchGenerationException so the processor marks the
     * whole batch failed (rather than aborting the run).
     *
     * @return Closure(array<int, array<string, mixed>|Model>): BatchResponse
     */
    protected function buildBatchResponseGenerator(): Closure
    {
        $promptBuilder = new BatchPromptBuilder($this->resolveIdentifierKey(), $this->promptContextColumns);
        $schemaResolver = $this->resolveBatchSchemaResolver();
        $userInput = $this->userInput;

        return function (array $batch) use ($promptBuilder, $schemaResolver, $userInput): BatchResponse {
            $instruction = $promptBuilder->build($this->prompt, $batch, $userInput);

            $agent = (new SolarisAgent)->configure($instruction, [], $schemaResolver);

            if ($this->tools !== null) {
                $agent->withTools($this->tools);
            }

            $this->options->applyTo($agent);

            try {
                $response = $this->callAgent($agent, $instruction);
            } catch (AiException $e) {
                throw new BatchGenerationException($e->getMessage(), (int) $e->getCode(), $e);
            }

            return BatchResponse::fromArray($response->toArray());
        };
    }

    /**
     * @return Closure(JsonSchemaTypeFactory): array<string, Type>
     */
    protected function resolveBatchSchemaResolver(): Closure
    {
        $modelClass = $this->modelClass;
        $identifierKey = $this->resolveIdentifierKey();

        return fn (JsonSchemaTypeFactory $schema): array => (new RecordsSchemaBuilder)->build(
            $schema,
            $modelClass,
            $identifierKey,
            $this->onlyColumns,
            $this->exceptColumns,
            $this->columnHints,
            $this->columnEnums,
        );
    }

    protected function resolveIdentifierKey(): string
    {
        if ($this->writeTerminal === RecordWriter::UPDATE) {
            assert($this->modelClass !== null);

            return (new ($this->modelClass)())->getKeyName();
        }

        return '_index';
    }

    protected function createTrackedRun(?int $total): SolarisBatchRun
    {
        $userId = $this->hasUser ? $this->user?->getAuthIdentifier() : auth()->id();

        return SolarisBatchRun::start(
            actionName: $this->sourceName,
            userId: $userId === null ? null : (string) $userId,
            page: $this->livewire !== null ? $this->livewire::class : null,
            total: $total,
            userInput: $this->userInput,
            completionHandlers: $this->completionHandlers,
            attachFailureReport: $this->attachFailureReport,
        );
    }

    protected function runSingleStructuredCall(): GenerationResult
    {
        $prompt = (string) ($this->prompt instanceof View ? $this->prompt->render() : $this->prompt);

        $agent = (new SolarisAgent)->configure($prompt, [], $this->schemaResolver);

        if ($this->tools !== null) {
            $agent->withTools($this->tools);
        }

        $this->options->applyTo($agent);

        $response = $this->callAgent($agent, $prompt);

        return new GenerationResult(
            data: $response->toArray(),
            usage: $response->usage,
            conversationId: $response->conversationId,
            response: $response,
        );
    }

    /**
     * One structured agent call with timing + Solaris event dispatch. Throws
     * {@see AiException} on failure (after firing SolarisResponseFailed); the batch
     * path re-wraps it as a BatchGenerationException.
     */
    protected function callAgent(SolarisAgent $agent, string $prompt): StructuredAgentResponse
    {
        $user = $this->hasUser ? $this->user : auth()->user();
        $startedAt = microtime(true);

        try {
            /** @var StructuredAgentResponse $response */
            $response = $agent->prompt($prompt, $this->attachments, $this->provider, $this->model, $this->timeout);
        } catch (\Throwable $original) {
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            $e = $original instanceof AiException
                ? $original
                : new AiException($original->getMessage(), (int) $original->getCode(), $original);

            SolarisResponseFailed::dispatch(
                $this->sourceName,
                $this->sourceClass,
                $e,
                $this->provider,
                $this->model,
                $durationMs,
                $user,
                $this->livewire,
            );

            throw $e;
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
        $usage = $response->usage;

        SolarisResponseReceived::dispatch(
            $this->sourceName,
            $this->sourceClass,
            $usage,
            $this->provider,
            $this->model,
            $durationMs,
            $user,
            $this->livewire,
        );

        SolarisPromptLogger::logUsage($this->sourceName, $usage, $this->provider, $this->model, $durationMs);

        return $response;
    }
}
