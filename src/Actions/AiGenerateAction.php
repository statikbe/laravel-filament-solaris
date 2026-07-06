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
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Files\File;
use LogicException;
use RuntimeException;
use Statikbe\FilamentSolaris\Concerns\HasGenerationOptions;
use Statikbe\FilamentSolaris\Concerns\HasQueuedExecution;
use Statikbe\FilamentSolaris\Concerns\HasUserInput;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Events\SolarisBatchStarted;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Statikbe\FilamentSolaris\Generation\AiGenerator;
use Statikbe\FilamentSolaris\Generation\GenerationResult;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;
use Statikbe\FilamentSolaris\Sanitizers\Sanitizer;
use Statikbe\FilamentSolaris\Support\Batch\BatchGenerationException;
use Statikbe\FilamentSolaris\Support\Batch\BatchPromptBuilder;
use Statikbe\FilamentSolaris\Support\Batch\BatchResponse;
use Statikbe\FilamentSolaris\Support\Batch\CompletionHandlerRunner;
use Statikbe\FilamentSolaris\Support\Batch\RecordsSchemaBuilder;
use Statikbe\FilamentSolaris\Support\Batch\RecordWriter;
use Statikbe\FilamentSolaris\Support\SolarisNotification;
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
    use HasQueuedExecution;
    use HasUserInput;

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

    protected bool|Closure|null $tracked = null;

    /** @var array<int, class-string>|null */
    protected ?array $completionHandlers = null;

    protected bool|Closure|null $attachFailureReport = null;

    protected bool|Closure $liveBatchUpdates = false;

    /** @var Closure|Sanitizer|array<int, Closure|Sanitizer>|null */
    protected Closure|Sanitizer|array|null $sanitizer = null;

    /** @var array<string, Closure|Sanitizer|array<int, Closure|Sanitizer>> */
    protected array $fieldSanitizers = [];

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
        $this->writeTerminal = RecordWriter::CREATE;
        $this->writeTerminalCount++;

        return $this;
    }

    public function updateRecords(): static
    {
        $this->writeTerminal = RecordWriter::UPDATE;
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
     * Sanitize every generated string value before write-back (stored-XSS guard).
     * A closure wraps in CallableSanitizer, an array in CompositeSanitizer.
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

    protected function applySanitizersTo(AiGenerator $generator): void
    {
        if ($this->sanitizer !== null) {
            $generator->sanitize($this->sanitizer);
        }

        foreach ($this->fieldSanitizers as $field => $sanitizer) {
            $generator->sanitizeField($field, $sanitizer);
        }
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
     * Run-completion handler(s) — one class or an ordered list. Replaces the default
     * notification handler; include it explicitly to keep notifications. Class strings
     * (not closures) so they serialize onto the queue. See spec 31.
     *
     * @param  class-string|array<int, class-string>  $handlers
     */
    public function onCompletion(string|array $handlers): static
    {
        $this->completionHandlers = is_array($handlers) ? array_values($handlers) : [$handlers];

        return $this;
    }

    /**
     * Per-action override for the "Download failures" actions on the completion
     * notification (default handler). Overrides config `batch_tracking.completion.failure_report`
     * for this action; pass `false` to suppress the download links even when the
     * global flag is on. Requires a tracked/queued run (the report reads persisted
     * problems).
     */
    public function withFailureReport(bool|Closure $attach = true): static
    {
        $this->attachFailureReport = $attach;

        return $this;
    }

    /**
     * Resolve whether this run attaches the failure report: per-action setting →
     * config → default true. Stashed into the run's meta at dispatch so the
     * (action-less) completion handler can honor it on the queue.
     */
    protected function resolveAttachFailureReport(): bool
    {
        if ($this->attachFailureReport !== null) {
            return (bool) $this->evaluate($this->attachFailureReport);
        }

        return FilamentSolaris::config()->shouldAttachBatchFailureReport();
    }

    /**
     * While the current user has an in-flight run of this action, reflect progress on
     * the button: disable it (re-run guard), show a progress tooltip, and self-poll
     * until the run finishes. Pairs with ->queued(). See spec 34.
     */
    public function liveBatchUpdates(bool|Closure $enabled = true): static
    {
        $this->liveBatchUpdates = $enabled;

        $this->disabled(fn (): bool => $this->activeLiveRun() !== null);

        $this->tooltip(function (): ?string {
            if (($run = $this->activeLiveRun()) === null) {
                return null;
            }

            return filament_solaris_trans('actions.batch_progress', [
                'done' => $run->succeeded + $run->failed,
                'total' => $run->total ?? '?',
                'failed' => $run->failed,
            ]);
        });

        // merge: true so we don't clobber a user-set ->extraAttributes() (and vice versa).
        $this->extraAttributes(fn (): array => $this->activeLiveRun() !== null
            ? ['wire:poll.'.FilamentSolaris::config()->getBatchLiveUpdatesPollInterval() => '']
            : [], merge: true);

        return $this;
    }

    protected function liveBatchUpdatesEnabled(): bool
    {
        return (bool) $this->evaluate($this->liveBatchUpdates);
    }

    /**
     * Latest in-flight run of this action for the current user, or null when live
     * updates are disabled / there is no in-flight run / no authenticated user.
     * Re-queried per render (no memo) so it stays fresh across wire:poll ticks.
     */
    protected function activeLiveRun(): ?SolarisBatchRun
    {
        if (! $this->liveBatchUpdatesEnabled()) {
            return null;
        }

        $userId = auth()->id();

        if ($userId === null) {
            return null;
        }

        return SolarisBatchRun::query()
            ->where('action_name', $this->getName())
            ->where('user_id', (string) $userId)
            ->where('status', BatchRunStatus::Processing)
            ->latest('started_at')
            ->first();
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

        // From-scratch create (no ->sourceRecords()): a write terminal → the
        // service seeds + writes + finalizes (inline or queued). Reached only for
        // createRecords; updateRecords always has a source (validated upstream).
        if ($this->writeTerminal !== null) {
            $this->executeFromScratchCreate($userInput);

            return;
        }

        if (AiGenerateActionFake::isActive()) {
            $this->executeFake($userInput);

            return;
        }

        $result = $this->runSingleCallViaGenerator($userInput);

        if ($result === null) {
            return;
        }

        $this->handleSingleCallResponse($result->data, $userInput);
    }

    /**
     * Seed-from-scratch create via the headless service. The service throws on an
     * AI failure (wrapped as BatchGenerationException); convert it back to the
     * action's user-facing notification — translated for a real AiException, the
     * raw message for a fake-simulated error (matching the old executeFake UX).
     *
     * @param  array<string, mixed>  $userInput
     */
    protected function executeFromScratchCreate(array $userInput): void
    {
        $generator = $this->makeFromScratchGenerator($userInput);

        if ($this->isQueued($userInput)) {
            $generator->runQueued();
            $this->sendQueuedStartedNotification();

            return;
        }

        try {
            $generator->runInline();
        } catch (BatchGenerationException $e) {
            $previous = $e->getPrevious();

            $previous instanceof AiException
                ? SolarisNotification::sendAiErrorNotification($previous)
                : Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    /**
     * Translate this action's resolved from-scratch config into a headless
     * {@see AiGenerator} (createRecords, no source). Under a fake, inject the
     * canned single-call response generator.
     *
     * @param  array<string, mixed>  $userInput
     */
    protected function makeFromScratchGenerator(array $userInput): AiGenerator
    {
        ['provider' => $provider, 'model' => $model] = $this->resolveProviderAndModel();
        $attachments = $this->resolveAttachments($userInput);

        $generator = AiGenerator::make()
            ->eventSource($this->getName(), static::class)
            ->forModel($this->modelClass)
            ->only($this->onlyColumns)
            ->except($this->exceptColumns)
            ->columnHints($this->columnHints)
            ->columnEnums($this->columnEnums)
            ->count((int) $this->evaluate($this->recordCount, ['userInput' => $userInput]))
            ->createRecords()
            ->prompt($this->wrapFromScratchInstruction())
            ->userInput($userInput)
            ->provider($provider, $model)
            ->timeout($this->resolveTimeout())
            ->options($this->resolveGenerationOptions())
            ->attachments($attachments)
            ->onCompletion($this->resolveCompletionHandlers())
            ->withFailureReport($this->resolveAttachFailureReport())
            ->forLivewire($this->getLivewire())
            ->forUser(auth()->user());

        $this->applySanitizersTo($generator);

        if (AiGenerateActionFake::isActive()) {
            $generator->responseGenerator(
                $this->makeFromScratchFakeResponseGenerator($userInput, $attachments, $provider, $model),
            );
        }

        return $generator;
    }

    /**
     * Pre-wrap a Filament-DI instruction closure into the plain `fn($userInput)`
     * shape {@see BatchPromptBuilder::fromScratch()} expects (string/View pass
     * through), so the service stays Filament-free.
     */
    protected function wrapFromScratchInstruction(): string|View|Closure
    {
        $instruction = $this->instruction;

        if ($instruction instanceof Closure) {
            $closure = $instruction;

            return fn (array $userInput): mixed => $this->evaluate($closure, ['userInput' => $userInput]);
        }

        return $instruction ?? '';
    }

    /**
     * Fake single-call response generator injected into the from-scratch
     * {@see AiGenerator} under a fake: replay the canned response, record the call,
     * fire the fake events — throwing BatchGenerationException on a simulated error.
     *
     * @param  array<string, mixed>  $userInput
     * @param  array<int, File>  $attachments
     * @return Closure(array<int, array<string, mixed>|Model>): BatchResponse
     */
    protected function makeFromScratchFakeResponseGenerator(array $userInput, array $attachments, mixed $provider, ?string $model): Closure
    {
        return function (array $batch) use ($userInput, $attachments, $provider, $model): BatchResponse {
            $fake = AiGenerateActionFake::getInstance();
            $rawResponse = $fake->getResponse();
            $fake->recordCall($this->getName(), $rawResponse, $userInput, $attachments);

            if ($fake->shouldSimulateError()) {
                $this->dispatchFakeResponseFailed($fake->getErrorMessage(), $provider, $model);

                throw new BatchGenerationException($fake->getErrorMessage());
            }

            $this->dispatchFakeResponseReceived($provider, $model);

            return BatchResponse::fromArray($rawResponse);
        };
    }

    /**
     * Resolve the single-call configuration and run it through the headless
     * AiGenerator. Returns null (after sending the error notification) when the
     * call fails, so the caller can short-circuit — mirroring the previous
     * executeAiCall() contract.
     *
     * @param  array<string, mixed>  $userInput
     */
    protected function runSingleCallViaGenerator(array $userInput): ?GenerationResult
    {
        ['provider' => $provider, 'model' => $model] = $this->resolveProviderAndModel();

        $generator = AiGenerator::make()
            ->prompt($this->resolveInstruction($userInput))
            ->schema($this->resolveSchemaResolver())
            ->provider($provider, $model)
            ->timeout($this->resolveTimeout())
            ->options($this->resolveGenerationOptions())
            ->attachments($this->resolveAttachments($userInput))
            ->eventSource($this->getName(), static::class)
            ->forLivewire($this->getLivewire())
            ->forUser(auth()->user());

        try {
            return $generator->runInline();
        } catch (AiException $e) {
            SolarisNotification::sendAiErrorNotification($e);

            return null;
        }
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
                // forModel handler mode: hand over a BatchResponse with the
                // synthetic identifier key stripped from each record, so handlers
                // never see the echoed _index / primary key. (Write terminals never
                // reach here — createRecords routes through the service.)
                $batchResponse = BatchResponse::fromArray($responseData);
                $this->runHandler($this->stripIdentifierKey($batchResponse, $this->resolveIdentifierKey()), $userInput);

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
        return BatchPromptBuilder::appendUserContext($instruction, $userInput);
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
        $modelClass = $this->modelClass;

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

    /**
     * Identifier key for the records-loop and single-call createRecords paths.
     * - updateRecords: the model's primary key column name (validated upstream that source rows are Models).
     * - createRecords (with or without source): `_index`.
     */
    protected function resolveIdentifierKey(): string
    {
        if ($this->writeTerminal === RecordWriter::UPDATE) {
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
        if ($this->writeTerminal === RecordWriter::UPDATE && $this->source === null) {
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
        $generator = $this->makeBatchGenerator($userInput);

        if ($this->isQueued($userInput)) {
            $generator->runQueued();
            $this->sendQueuedStartedNotification();

            return;
        }

        $generator->runInline();
    }

    /**
     * Translate this action's resolved batch config into a headless
     * {@see AiGenerator}. Under a fake, inject the canned per-batch response
     * generator; otherwise the service makes the real agent call itself.
     *
     * @param  array<string, mixed>  $userInput
     */
    protected function makeBatchGenerator(array $userInput): AiGenerator
    {
        ['provider' => $provider, 'model' => $model] = $this->resolveProviderAndModel();
        $attachments = $this->resolveAttachments($userInput);

        $generator = AiGenerator::make()
            ->eventSource($this->getName(), static::class)
            ->forModel($this->modelClass)
            ->only($this->onlyColumns)
            ->except($this->exceptColumns)
            ->columnHints($this->columnHints)
            ->columnEnums($this->columnEnums)
            ->sourceRecords($this->resolveRecordsSource($userInput))
            ->prompt($this->wrapBatchInstruction())
            ->promptContextColumns($this->promptContextColumns)
            ->userInput($userInput)
            ->batchSize($this->resolveBatchSize($userInput))
            ->provider($provider, $model)
            ->timeout($this->resolveTimeout())
            ->options($this->resolveGenerationOptions())
            ->attachments($attachments)
            ->trackBatchRuns($this->isTracked($userInput))
            ->onCompletion($this->resolveCompletionHandlers())
            ->withFailureReport($this->resolveAttachFailureReport())
            ->forLivewire($this->getLivewire())
            ->forUser(auth()->user());

        $this->writeTerminal === RecordWriter::UPDATE
            ? $generator->updateRecords()
            : $generator->createRecords();

        $this->applySanitizersTo($generator);

        if (AiGenerateActionFake::isActive()) {
            $generator->responseGenerator(
                $this->makeFakeResponseGenerator($userInput, $attachments, $provider, $model),
            );
        }

        return $generator;
    }

    /**
     * Pre-wrap a Filament-DI instruction closure into the plain
     * `fn($rows, $userInput)` shape {@see BatchPromptBuilder} expects (string/View
     * pass through unchanged), so the service stays Filament-free.
     */
    protected function wrapBatchInstruction(): string|View|Closure
    {
        $instruction = $this->instruction;

        if ($instruction instanceof Closure) {
            $closure = $instruction;

            return fn (array $rows, array $userInput): mixed => $this->evaluate($closure, [
                'rows' => $rows,
                'userInput' => $userInput,
            ]);
        }

        return $instruction ?? '';
    }

    /**
     * @return array<int, class-string>
     */
    protected function resolveCompletionHandlers(): array
    {
        return CompletionHandlerRunner::resolve($this->completionHandlers);
    }

    /**
     * Fake per-batch response generator injected into the {@see AiGenerator} under
     * a fake: replay the canned response, record the call, and fire the fake
     * events — throwing BatchGenerationException so the processor marks the batch
     * failed. The real path lives in AiGenerator. Still builds the instruction so
     * prompt-closure errors surface under the fake.
     *
     * @param  array<string, mixed>  $userInput
     * @param  array<int, File>  $attachments
     * @return Closure(array<int, array<string, mixed>|Model>): BatchResponse
     */
    protected function makeFakeResponseGenerator(array $userInput, array $attachments, mixed $provider, ?string $model): Closure
    {
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

    protected function makeBatchPromptBuilder(): BatchPromptBuilder
    {
        return new BatchPromptBuilder($this->resolveIdentifierKey(), $this->promptContextColumns);
    }

    /**
     * @param  array<int, array<string, mixed>|Model>  $batch
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    protected function enrichBatchWithIdentifier(array $batch): array
    {
        return $this->makeBatchPromptBuilder()->enrich($batch);
    }

    /**
     * Pre-wrap a Filament-DI instruction closure into the plain
     * `fn($rows, $userInput)` shape {@see BatchPromptBuilder} expects, so prompt
     * assembly stays Filament-free while the action keeps closure DI.
     *
     * @param  array<int, array<string, mixed>|Model>  $batch
     * @param  array<string, mixed>  $userInput
     */
    protected function buildBatchInstruction(array $batch, array $userInput): string
    {
        $instruction = $this->instruction;

        if ($instruction instanceof Closure) {
            $closure = $instruction;
            $instruction = fn (array $rows, array $userInput): mixed => $this->evaluate($closure, [
                'rows' => $rows,
                'userInput' => $userInput,
            ]);
        }

        return $this->makeBatchPromptBuilder()->build($instruction, $batch, $userInput);
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
