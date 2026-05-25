<?php

namespace Statikbe\FilamentSolaris\Actions;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\StructuredAgentResponse;
use LogicException;
use RuntimeException;
use Statikbe\FilamentSolaris\Agents\SolarisAgent;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
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
    public const RECORDS_KEY = 'records';

    protected string|View|Closure|null $instruction = null;

    protected ?Closure $schemaResolver = null;

    /** @var class-string|null */
    protected ?string $modelClass = null;

    protected int|Closure $recordCount = 1;

    /** @var array<string> */
    protected array $onlyColumns = [];

    /** @var array<string> */
    protected array $exceptColumns = [];

    protected ?Closure $handler = null;

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
     * @param  Closure  $handler  receives `array $data` (full decoded response) + Filament DI; when forModel() is used, also `array $records`.
     */
    public function handleUsing(Closure $handler): static
    {
        $this->handler = $handler;

        return $this;
    }

    public function execute(): void
    {
        $this->validateConfiguration();

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

        $this->dispatchToHandler($response->toArray());
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
        $this->dispatchToHandler($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function dispatchToHandler(array $data): void
    {
        try {
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
            $properties = (new ModelSchemaResolver)->resolve($schema, $this->modelClass, $this->onlyColumns, $this->exceptColumns);

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

        if ($this->handler === null) {
            throw new RuntimeException('AiGenerateAction requires a ->handleUsing() handler.');
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

    // ── Testing ──────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $response
     */
    public static function fake(array $response = []): AiGenerateActionFake
    {
        return AiGenerateActionFake::activate($response);
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
