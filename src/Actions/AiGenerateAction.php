<?php

namespace Statikbe\FilamentSolaris\Actions;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Laravel\Ai\Files\File;
use Laravel\Ai\Responses\StructuredAgentResponse;
use LogicException;
use RuntimeException;
use Statikbe\FilamentSolaris\Agents\SolarisAgent;
use Statikbe\FilamentSolaris\Concerns\HasGenerationOptions;
use Statikbe\FilamentSolaris\Concerns\HasUserInput;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Statikbe\FilamentSolaris\Support\BatchOutcome;
use Statikbe\FilamentSolaris\Support\BatchResponse;
use Statikbe\FilamentSolaris\Support\FailedRecord;
use Statikbe\FilamentSolaris\Support\ModelSchemaResolver;
use Statikbe\FilamentSolaris\Testing\AiGenerateActionFake;
use Statikbe\FilamentSolaris\Testing\AiGenerateActionFakeException;

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

    public const RECORDS_KEY = 'records';

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

    /** @var Builder<Model>|Collection<int, array<string, mixed>>|EloquentCollection<int, Model>|array<int, array<string, mixed>|Model>|Closure|null */
    protected Builder|Collection|EloquentCollection|array|Closure|null $source = null;

    protected ?string $writeTerminal = null;

    protected int $writeTerminalCount = 0;

    /** @var array<string> */
    protected array $promptContextColumns = [];

    protected int|Closure $batchSize = 10;

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
     * @param  Closure  $handler  receives `array $data` (full decoded response) + Filament DI; when forModel() is used, also `array $records`.
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
     */
    public function batchSize(int|Closure $size): static
    {
        $this->batchSize = $size;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $userInput
     */
    public function execute(array $userInput = []): void
    {
        $this->validateConfiguration();

        if ($this->source !== null) {
            $this->executeRecordsLoop($userInput);

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

                if ($this->writeTerminal === self::WRITE_CREATE) {
                    $identifierKey = $this->resolveIdentifierKey();
                    $succeeded = 0;

                    foreach ($batchResponse->records as $row) {
                        unset($row[$identifierKey]);

                        try {
                            $this->modelClass::create($row);
                            $succeeded++;
                        } catch (\Throwable $e) {
                            report($e);
                        }
                    }

                    if ($batchResponse->failed !== []) {
                        $this->sendBatchSummary($succeeded, count($batchResponse->failed));
                    }

                    return;
                }

                // handler mode in forModel: pass the BatchResponse DTO.
                $this->evaluate($this->handler, [
                    'data' => $batchResponse,
                    'userInput' => $userInput,
                ]);

                return;
            }

            // custom outputSchema mode: raw assoc, unchanged.
            $this->evaluate($this->handler, [
                'data' => $responseData,
                'userInput' => $userInput,
            ]);
        } catch (\Throwable $e) {
            report($e);
            Notification::make()
                ->title(filament_solaris_trans('notifications.handler_error'))
                ->danger()
                ->send();
        }
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
            $count = (int) $this->evaluate($this->recordCount);
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
                'failed' => $schema->array()->items($schema->object([
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
        $this->guardClosureArgs();

        $rows = $this->resolveRecordsSource($userInput);
        ['provider' => $provider, 'model' => $model] = $this->resolveProviderAndModel();
        $timeout = $this->resolveTimeout();
        $resolver = $this->resolveSchemaResolver();
        $attachments = $this->resolveAttachments($userInput);
        $batchSize = $this->resolveBatchSize($userInput);

        $succeeded = 0;
        $failures = [];

        foreach ($this->chunkRows($rows, $batchSize) as $batch) {
            try {
                $outcome = $this->processBatch($batch, $resolver, $provider, $model, $timeout, $userInput, $attachments);
                $succeeded += $outcome->succeeded;
                $failures = array_merge($failures, $outcome->failures);
            } catch (AiGenerateActionFakeException $e) {
                throw $e;
            } catch (\Throwable $e) {
                report($e);
                $failures = array_merge($failures, $this->markBatchFailed($batch, 'AI call error'));
            }
        }

        $this->sendBatchSummary($succeeded, count($failures));
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
        if (! $row instanceof Model) {
            throw new RuntimeException('updateRecords source items must be Eloquent models, got '.get_debug_type($row));
        }

        $row->update($attrs);
    }

    /**
     * @param  iterable<int, array<string, mixed>|Model>  $rows
     * @return iterable<int, array<int, array<string, mixed>|Model>>
     */
    protected function chunkRows(iterable $rows, int $batchSize): iterable
    {
        if ($rows instanceof Collection || $rows instanceof EloquentCollection) {
            foreach ($rows->chunk($batchSize) as $chunk) {
                yield array_values($chunk->all());
            }

            return;
        }

        $rowsArray = is_array($rows) ? $rows : iterator_to_array($rows);
        foreach (array_chunk($rowsArray, $batchSize) as $chunk) {
            yield $chunk;
        }
    }

    /**
     * @param  array<int, array<string, mixed>|Model>  $batch
     * @return array<int, FailedRecord>
     */
    protected function markBatchFailed(array $batch, string $reason): array
    {
        $identifierKey = $this->resolveIdentifierKey();

        return array_map(function ($row) use ($identifierKey, $reason): FailedRecord {
            $id = $identifierKey === '_index'
                ? null  // index unrecoverable outside the foreach
                : ($row instanceof Model ? $row->getKey() : null);

            return new FailedRecord(identifier: $id, reason: $reason);
        }, $batch);
    }

    /**
     * @param  array<int, array<string, mixed>|Model>  $batch
     */
    protected function reconcileBatch(array $batch, BatchResponse $response): BatchOutcome
    {
        $identifierKey = $this->resolveIdentifierKey();

        // Build identifier -> input row lookup.
        $lookup = [];
        foreach ($batch as $index => $row) {
            $id = $identifierKey === '_index'
                ? $index
                : ($row instanceof Model ? $row->getKey() : null);
            $lookup[(string) $id] = $row;
        }

        $succeeded = 0;
        $failures = [];

        foreach ($response->records as $outputRecord) {
            $id = $outputRecord[$identifierKey] ?? null;

            if ($id === null || ! isset($lookup[(string) $id])) {
                report(new RuntimeException('AiGenerateAction: hallucinated or missing identifier in records output: '.json_encode($outputRecord)));

                continue;
            }

            $row = $lookup[(string) $id];
            unset($lookup[(string) $id]);

            $attrs = $outputRecord;
            unset($attrs[$identifierKey]);

            try {
                $this->writeRow($row, $attrs);
                $succeeded++;
            } catch (\Throwable $e) {
                report($e);
                $failures[] = new FailedRecord(identifier: $id, reason: 'write error: '.$e->getMessage());
            }
        }

        foreach ($response->failed as $failure) {
            $id = $failure->identifier;
            if ($id !== null && isset($lookup[(string) $id])) {
                unset($lookup[(string) $id]);
            }
            $failures[] = $failure;
        }

        foreach ($lookup as $id => $row) {
            $failures[] = new FailedRecord(identifier: $id, reason: 'no response from AI');
        }

        return new BatchOutcome($succeeded, $failures);
    }

    /**
     * @param  array<int, array<string, mixed>|Model>  $batch
     * @param  Closure(JsonSchemaTypeFactory): array<string, Type>  $resolver
     * @param  array<string, mixed>  $userInput
     * @param  array<int, File>  $attachments
     */
    protected function processBatch(
        array $batch,
        Closure $resolver,
        mixed $provider,
        ?string $model,
        ?int $timeout,
        array $userInput,
        array $attachments,
    ): BatchOutcome {
        if (AiGenerateActionFake::isActive()) {
            return $this->processFakeBatch($batch, $userInput, $attachments, $provider, $model);
        }

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
            return new BatchOutcome(0, $this->markBatchFailed($batch, 'AI call error'));
        }

        $batchResponse = BatchResponse::fromArray($response->toArray());

        return $this->reconcileBatch($batch, $batchResponse);
    }

    /**
     * @param  array<int, array<string, mixed>|Model>  $batch
     * @param  array<string, mixed>  $userInput
     * @param  array<int, File>  $attachments
     */
    protected function processFakeBatch(
        array $batch,
        array $userInput,
        array $attachments,
        mixed $provider,
        ?string $model,
    ): BatchOutcome {
        // Resolve the instruction so closure errors and DI mistakes surface in tests.
        $this->buildBatchInstruction($batch, $userInput);

        $fake = AiGenerateActionFake::getInstance();
        $rawResponse = $fake->getResponse();

        // Build the rows array passed to the fake's recordCall for assertions.
        [, $rows] = $this->enrichBatchWithIdentifier($batch);
        $fake->recordCall($this->getName(), $rawResponse, $userInput, $attachments, $rows);

        if ($fake->shouldSimulateError()) {
            $this->dispatchFakeResponseFailed($fake->getErrorMessage(), $provider, $model);

            return new BatchOutcome(0, $this->markBatchFailed($batch, $fake->getErrorMessage()));
        }

        $this->dispatchFakeResponseReceived($provider, $model);

        $batchResponse = BatchResponse::fromArray($rawResponse);

        return $this->reconcileBatch($batch, $batchResponse);
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

    /**
     * @param  array<int, array<string, mixed>|Model>  $batch
     */
    protected function appendBatchInstructions(string $instruction, array $batch): string
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
            $rows = array_map(
                fn ($row): array => $row instanceof Model ? $row->getAttributes() : $row,
                $batch,
            );
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
        $instruction = $this->appendBatchInstructions($instruction, $batch);

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
