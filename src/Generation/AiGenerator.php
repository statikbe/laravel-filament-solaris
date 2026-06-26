<?php

namespace Statikbe\FilamentSolaris\Generation;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
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
use Statikbe\FilamentSolaris\Events\SolarisBatchStarted;
use Statikbe\FilamentSolaris\Events\SolarisResponseFailed;
use Statikbe\FilamentSolaris\Events\SolarisResponseReceived;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;
use Statikbe\FilamentSolaris\Support\Batch\BatchGenerationException;
use Statikbe\FilamentSolaris\Support\Batch\BatchPromptBuilder;
use Statikbe\FilamentSolaris\Support\Batch\BatchResponse;
use Statikbe\FilamentSolaris\Support\Batch\BatchSummary;
use Statikbe\FilamentSolaris\Support\Batch\RecordsSchemaBuilder;
use Statikbe\FilamentSolaris\Support\Batch\RecordWriter;
use Statikbe\FilamentSolaris\Support\Batch\Runners\InlineRunner;
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

    protected int $batchSize = 10;

    /** @var array<string> */
    protected array $promptContextColumns = [];

    /** @var array<string, mixed> */
    protected array $userInput = [];

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

    protected function runBatch(): BatchSummary
    {
        if ($this->sourceRecords === null) {
            throw new RuntimeException('AiGenerator batch (createRecords/updateRecords) currently requires ->sourceRecords().');
        }

        if ($this->modelClass === null) {
            throw new RuntimeException('AiGenerator ->createRecords()/->updateRecords() require ->forModel().');
        }

        $rows = $this->sourceRecords;
        $modelClass = $this->modelClass;
        $writeTerminal = $this->writeTerminal;

        $run = $this->tracked ? $this->createTrackedRun($rows) : null;

        return (new InlineRunner)->run(
            actionName: $this->sourceName,
            rows: $rows,
            batchSize: $this->batchSize,
            identifierKey: $this->resolveIdentifierKey(),
            generateResponse: $this->responseGeneratorOverride ?? $this->buildBatchResponseGenerator(),
            persistRecord: fn (mixed $source, array $attrs) => (new RecordWriter($modelClass, $writeTerminal))->write($source, $attrs),
            run: $run,
            completionHandlers: $this->completionHandlers,
            userInput: $this->userInput,
        );
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

    /**
     * @param  iterable<int, array<string, mixed>|Model>  $rows
     */
    protected function createTrackedRun(iterable $rows): SolarisBatchRun
    {
        $userId = $this->hasUser ? $this->user?->getAuthIdentifier() : auth()->id();

        $run = SolarisBatchRun::create([
            'action_name' => $this->sourceName,
            'user_id' => $userId === null ? null : (string) $userId,
            'page' => $this->livewire !== null ? $this->livewire::class : null,
            'status' => BatchRunStatus::Processing,
            'total' => is_countable($rows) ? count($rows) : null,
            'meta' => [
                'userInput' => $this->userInput,
                'completionHandlers' => $this->completionHandlers,
                'attach_failure_report' => $this->attachFailureReport,
            ],
            'started_at' => now(),
        ]);

        SolarisBatchStarted::dispatch($run->id, $run->action_name, $run->user_id, $run->page, $run->total);

        return $run;
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
