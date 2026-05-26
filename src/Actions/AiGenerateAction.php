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
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\StructuredAgentResponse;
use LogicException;
use RuntimeException;
use Statikbe\FilamentSolaris\Agents\SolarisAgent;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->icon(FilamentSolaris::config()->getActionIcon());

        $this->action(function (AiGenerateAction $action): void {
            $action->execute();
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
     * Whitelist of column names sent into the per-row `## Current record`
     * context block. Default = all the row's attributes (auto-exclusions aside).
     *
     * @param  array<string>  $columns
     */
    public function promptContextColumns(array $columns): static
    {
        $this->promptContextColumns = $columns;

        return $this;
    }

    public function execute(): void
    {
        $this->validateConfiguration();

        if ($this->source !== null) {
            $this->executeRecordsLoop();

            return;
        }

        if (AiGenerateActionFake::isActive()) {
            $this->executeFake();

            return;
        }

        $instruction = $this->resolveInstruction();
        $resolver = $this->resolveSchemaResolver();

        ['provider' => $provider, 'model' => $model] = $this->resolveProviderAndModel();
        $timeout = $this->resolveTimeout();

        $agent = (new SolarisAgent)->configure($instruction, [], $resolver);

        /** @var StructuredAgentResponse|null $response */
        $response = $this->executeAiCall(
            fn () => $agent->prompt($instruction, [], $provider, $model, $timeout),
            $provider,
            $model,
        );

        if ($response === null) {
            return;
        }

        $this->dispatchSingleResponse($response->toArray());
    }

    protected function executeFake(): void
    {
        $fake = AiGenerateActionFake::getInstance();
        $data = $fake->getResponse();
        $fake->recordCall($this->getName(), $data);

        ['provider' => $provider, 'model' => $model] = $this->resolveProviderAndModel();

        if ($fake->shouldSimulateError()) {
            $this->dispatchFakeResponseFailed($fake->getErrorMessage(), $provider, $model);
            Notification::make()->title($fake->getErrorMessage())->danger()->send();

            return;
        }

        $this->dispatchFakeResponseReceived($provider, $model);
        $this->dispatchSingleResponse($data);
    }

    /**
     * Single-response dispatch (no records loop): hand to user handler, or
     * for `createRecords` with no `->sourceRecords()`, per-row create from
     * `$data[RECORDS_KEY]`.
     *
     * @param  array<string, mixed>  $data
     */
    protected function dispatchSingleResponse(array $data): void
    {
        try {
            if ($this->writeTerminal === self::WRITE_CREATE) {
                /** @var array<int, array<string, mixed>> $records */
                $records = $data[self::RECORDS_KEY] ?? [];
                foreach ($records as $row) {
                    $this->modelClass::create($row);
                }

                return;
            }

            $this->evaluate($this->handler, [
                'data' => $data,
                'records' => $this->modelClass !== null ? ($data[self::RECORDS_KEY] ?? []) : null,
            ]);
        } catch (\Throwable $e) {
            report($e);
            Notification::make()
                ->title(filament_solaris_trans('notifications.handler_error'))
                ->danger()
                ->send();
        }
    }

    protected function resolveInstruction(): string
    {
        $instruction = $this->instruction;

        if ($instruction instanceof Closure) {
            $instruction = $this->evaluate($instruction);
        }

        if ($instruction instanceof View) {
            $instruction = $instruction->render();
        }

        $instruction = (string) $instruction;

        if ($this->modelClass !== null) {
            $count = (int) $this->evaluate($this->recordCount);
            $instruction = trim($instruction."\n\nGenerate {$count} records.");
        }

        return $instruction;
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

        return function (JsonSchemaTypeFactory $schema): array {
            $properties = (new ModelSchemaResolver)->resolve(
                $schema,
                $this->modelClass,
                $this->onlyColumns,
                $this->exceptColumns,
                $this->columnHints,
                $this->columnEnums,
            );

            return [self::RECORDS_KEY => $schema->array()->items($schema->object($properties))];
        };
    }

    /**
     * @return array{provider: Lab|array<int|string, string>|string|null, model: ?string}
     */
    protected function resolveProviderAndModel(): array
    {
        $action = $this->resolveActionProvider();

        if ($action !== null) {
            return $action;
        }

        $config = FilamentSolaris::config();

        return ['provider' => $config->getDefaultProvider(), 'model' => $config->getDefaultModel()];
    }

    protected function resolveTimeout(): ?int
    {
        return $this->resolveActionTimeout() ?? FilamentSolaris::config()->getDefaultTimeout();
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

    public function hasUserInput(): bool
    {
        return false;
    }

    // ── Records loop ─────────────────────────────────────────────

    protected function executeRecordsLoop(): void
    {
        $rows = $this->resolveRecordsSource();
        ['provider' => $provider, 'model' => $model] = $this->resolveProviderAndModel();
        $timeout = $this->resolveTimeout();

        $resolver = $this->resolveSchemaResolver();

        $succeeded = 0;
        $failed = 0;

        foreach ($rows as $row) {
            try {
                $attrs = $this->generateForRow($row, $resolver, $provider, $model, $timeout);

                if ($attrs === null) {
                    $failed++;

                    continue;
                }

                $this->writeRow($row, $attrs);
                $succeeded++;
            } catch (AiGenerateActionFakeException $e) {
                // Test-config bug — surface it, don't count as a row failure.
                throw $e;
            } catch (\Throwable $e) {
                report($e);
                $failed++;
            }
        }

        $this->sendBatchSummary($succeeded, $failed);
    }

    /**
     * @return iterable<int, array<string, mixed>|Model>
     */
    protected function resolveRecordsSource(): iterable
    {
        $source = $this->source instanceof Closure ? $this->evaluate($this->source) : $this->source;

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
     * @param  Closure(JsonSchemaTypeFactory): array<string, Type>  $resolver
     * @return array<string, mixed>|null AI output, or null on AI error (already reported by executeAiCall)
     */
    protected function generateForRow(array|Model $row, Closure $resolver, mixed $provider, ?string $model, ?int $timeout): ?array
    {
        if (AiGenerateActionFake::isActive()) {
            // Still resolve the per-row instruction so the prompt closure runs:
            // surfaces undefined-variable / bad-template errors under ::fake(),
            // and lets the `$row` named injection be exercised end-to-end in tests.
            $this->resolveInstructionForRow($row);

            $fake = AiGenerateActionFake::getInstance();
            $data = $fake->getResponse();
            $fake->recordCall($this->getName(), $data);

            if ($fake->shouldSimulateError()) {
                $this->dispatchFakeResponseFailed($fake->getErrorMessage(), $provider, $model);

                return null;
            }

            $this->dispatchFakeResponseReceived($provider, $model);

            return $data;
        }

        $instruction = $this->resolveInstructionForRow($row);
        $agent = (new SolarisAgent)->configure($instruction, [], $resolver);

        /** @var StructuredAgentResponse|null $response */
        $response = $this->executeAiCall(
            fn () => $agent->prompt($instruction, [], $provider, $model, $timeout),
            $provider,
            $model,
            static fn (): null => null,  // suppress per-row error notification; summary covers it
        );

        return $response?->toArray();
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
     * @param  array<string, mixed>|Model  $row
     */
    protected function resolveInstructionForRow(array|Model $row): string
    {
        $instruction = $this->instruction;

        if ($instruction instanceof Closure) {
            $instruction = $this->evaluate($instruction, [
                'row' => $row instanceof Model ? $row->getAttributes() : $row,
            ]);
        }

        if ($instruction instanceof View) {
            $instruction = $instruction->render();
        }

        $instruction = (string) $instruction;

        $context = $this->buildContextForRow($row);

        if ($context !== []) {
            $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $instruction = trim($instruction)."\n\n## Current record\n```json\n{$json}\n```";
        }

        return $instruction;
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
}
