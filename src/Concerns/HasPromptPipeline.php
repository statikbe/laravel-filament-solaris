<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Closure;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Statikbe\FilamentSolaris\Agents\ConversationalSolarisAgent;
use Statikbe\FilamentSolaris\Agents\SolarisAgent;
use Statikbe\FilamentSolaris\Contracts\PromptBuilder;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Statikbe\FilamentSolaris\Factories\ComponentFactory;
use Statikbe\FilamentSolaris\Prompts\InlinePromptBuilder;
use Statikbe\FilamentSolaris\Prompts\Presets\Preset;
use Statikbe\FilamentSolaris\Prompts\ViewPromptBuilder;
use Statikbe\FilamentSolaris\Support\SolarisNotification;
use Statikbe\FilamentSolaris\Support\SolarisPromptLogger;
use Statikbe\FilamentSolaris\Testing\AiActionFake;

trait HasPromptPipeline
{
    use HasTargetFields;
    use HasUserInput;

    protected ?PromptBuilder $promptBuilder = null;

    protected string|View|null $promptInstruction = null;

    protected string|Closure|null $localeOverride = null;

    /**
     * @var Lab|array<string, string>|array<int, string>|string|Closure|null
     */
    protected Lab|array|string|Closure|null $pipelineProvider = null;

    protected string|Closure|null $pipelineModel = null;

    protected int|Closure|null $pipelineTimeout = null;

    /** @var array<Tool|ProviderTool>|Closure|null */
    protected array|Closure|null $pipelineTools = null;

    protected float|int|Closure|null $pipelineTemperature = null;

    protected int|Closure|null $pipelineMaxTokens = null;

    protected int|Closure|null $pipelineMaxSteps = null;

    protected float|int|Closure|null $pipelineTopP = null;

    /**
     * Set an inline prompt instruction or a Blade view.
     */
    public function prompt(string|View $instruction): static
    {
        $this->promptBuilder = $instruction instanceof View
            ? new ViewPromptBuilder
            : new InlinePromptBuilder;

        $this->promptInstruction = $instruction;

        return $this;
    }

    /**
     * Set a Preset as the PromptBuilder.
     */
    public function preset(Preset $preset): static
    {
        $this->promptBuilder = $preset;

        return $this;
    }

    /**
     * Set a custom PromptBuilder instance directly.
     */
    public function promptBuilder(PromptBuilder $builder): static
    {
        $this->promptBuilder = $builder;

        return $this;
    }

    /**
     * Override the locale for this action.
     */
    public function locale(string|Closure $locale): static
    {
        $this->localeOverride = $locale;

        return $this;
    }

    /**
     * Resolve the locale for this action.
     */
    public function getLocale(): string
    {
        return $this->evaluate($this->localeOverride) ?? app()->getLocale();
    }

    /**
     * Set the AI provider (and optionally model) for this action.
     *
     * @param  Lab|array<string, string>|array<int, string>|string|Closure  $provider
     */
    public function provider(Lab|array|string|Closure $provider, string|Closure|null $model = null): static
    {
        $this->pipelineProvider = $provider;

        if ($model !== null) {
            $this->pipelineModel = $model;
        }

        return $this;
    }

    /**
     * Set the timeout in seconds for the AI call.
     */
    public function timeout(int|Closure $timeout): static
    {
        $this->pipelineTimeout = $timeout;

        return $this;
    }

    /**
     * Set the tools available to the agent for this action.
     *
     * @param  array<Tool|ProviderTool>|Closure  $tools
     */
    public function tools(array|Closure $tools): static
    {
        $this->pipelineTools = $tools;

        return $this;
    }

    /**
     * Set the sampling temperature for this action.
     */
    public function temperature(float|int|Closure|null $temperature): static
    {
        $this->pipelineTemperature = $temperature;

        return $this;
    }

    /**
     * Set the max output tokens for this action.
     */
    public function maxTokens(int|Closure|null $maxTokens): static
    {
        $this->pipelineMaxTokens = $maxTokens;

        return $this;
    }

    /**
     * Set the max tool-call steps for this action.
     */
    public function maxSteps(int|Closure|null $maxSteps): static
    {
        $this->pipelineMaxSteps = $maxSteps;

        return $this;
    }

    /**
     * Set the nucleus sampling top_p for this action.
     */
    public function topP(float|int|Closure|null $topP): static
    {
        $this->pipelineTopP = $topP;

        return $this;
    }

    /**
     * Resolve the timeout for this pipeline call.
     *
     * Resolution chain (highest to lowest priority):
     * 1. Action ->timeout()
     * 2. Config preset_providers[class].timeout
     * 3. Config default_timeout
     * 4. null (laravel/ai default)
     */
    protected function resolveTimeout(): ?int
    {
        $timeout = $this->evaluate($this->pipelineTimeout);

        if ($timeout !== null) {
            return $timeout;
        }

        $config = FilamentSolaris::config();

        if ($this->promptBuilder instanceof Preset) {
            $presetConfig = $config->getPresetProvider(get_class($this->promptBuilder));
            if ($presetConfig['timeout'] !== null) {
                return $presetConfig['timeout'];
            }
        }

        return $config->getDefaultTimeout();
    }

    /**
     * Resolve text-generation options for this pipeline call.
     *
     * Resolution chain per option (highest to lowest priority):
     * 1. Action ->temperature() / ->maxTokens() / ->maxSteps() / ->topP()
     * 2. Preset ->temperature() / ... method
     * 3. Config preset_providers[class].{temperature|max_tokens|max_steps|top_p}
     * 4. Config default_{temperature|max_tokens|max_steps|top_p}
     * 5. null (laravel/ai falls back to its own attribute defaults)
     *
     * @return array{temperature: ?float, max_tokens: ?int, max_steps: ?int, top_p: ?float}
     */
    protected function resolveOptions(): array
    {
        $config = FilamentSolaris::config();
        $preset = $this->promptBuilder instanceof Preset ? $this->promptBuilder : null;
        $presetConfig = $preset !== null ? $config->getPresetProvider(get_class($preset)) : [];

        $temperature = $this->evaluate($this->pipelineTemperature)
            ?? $preset?->getTemperature()
            ?? $presetConfig['temperature']
            ?? $config->getDefaultTemperature();

        $maxTokens = $this->evaluate($this->pipelineMaxTokens)
            ?? $preset?->getMaxTokens()
            ?? $presetConfig['max_tokens']
            ?? $config->getDefaultMaxTokens();

        $maxSteps = $this->evaluate($this->pipelineMaxSteps)
            ?? $preset?->getMaxSteps()
            ?? $presetConfig['max_steps']
            ?? $config->getDefaultMaxSteps();

        $topP = $this->evaluate($this->pipelineTopP)
            ?? $preset?->getTopP()
            ?? $presetConfig['top_p']
            ?? $config->getDefaultTopP();

        return [
            'temperature' => $temperature !== null ? (float) $temperature : null,
            'max_tokens' => $maxTokens,
            'max_steps' => $maxSteps,
            'top_p' => $topP !== null ? (float) $topP : null,
        ];
    }

    /**
     * Resolve the provider and model for this pipeline call.
     *
     * Resolution chain (highest to lowest priority):
     * 1. Action ->provider()
     * 2. Preset ->provider()
     * 3. Config preset_providers[class]
     * 4. Config default_provider / default_model
     * 5. null (laravel/ai uses its own defaults)
     *
     * @return array{provider: Lab|array|string|null, model: ?string}
     */
    protected function resolveProviderAndModel(): array
    {
        // 1. Action-level override
        $provider = $this->evaluate($this->pipelineProvider);
        if ($provider !== null) {
            return [
                'provider' => $provider,
                'model' => $this->evaluate($this->pipelineModel),
            ];
        }

        $config = FilamentSolaris::config();

        // 2. Preset-level override
        if ($this->promptBuilder instanceof Preset) {
            $presetProvider = $this->promptBuilder->getProvider();
            if ($presetProvider !== null) {
                return [
                    'provider' => $presetProvider,
                    'model' => $this->promptBuilder->getModel(),
                ];
            }

            // 3. Config preset_providers
            $presetConfig = $config->getPresetProvider(get_class($this->promptBuilder));
            if ($presetConfig['provider'] !== null) {
                return $presetConfig;
            }
        }

        // 4. Config default_provider / default_model
        return [
            'provider' => $config->getDefaultProvider(),
            'model' => $config->getDefaultModel(),
        ];
    }

    /**
     * Check if a PromptBuilder is configured.
     */
    public function hasPromptBuilder(): bool
    {
        return $this->promptBuilder !== null;
    }

    /**
     * Get the field names used to resolve a form schema component.
     *
     * Override this in consuming classes to include additional fields
     * (e.g. source fields in AiAction).
     *
     * @return array<string>
     */
    protected function getFieldNamesForSchemaResolution(): array
    {
        return $this->getTargetFields();
    }

    /**
     * Build the prompt and resolve target factories.
     *
     * @param  array<string, mixed>  $sourceData
     * @param  array<string, mixed>  $userInput
     * @return array{string, array<string, ComponentFactory>}
     */
    protected function buildPrompt(array $sourceData, array $userInput): array
    {
        $factories = $this->resolveTargetFactories();
        $record = $this->resolveRecord();
        $locale = $this->getLocale();
        $instruction = $this->promptInstruction ?? '';

        $prompt = $this->promptBuilder->build(
            $instruction,
            $sourceData,
            $factories,
            $record,
            $locale,
            $userInput,
        );

        return [$prompt, $factories];
    }

    /**
     * Run the AI pipeline with the given source data and user input.
     *
     * @param  array<string, mixed>  $sourceData
     * @param  array<string, mixed>  $userInput
     */
    protected function runPipeline(array $sourceData, array $userInput): void
    {
        [$prompt, $factories] = $this->buildPrompt($sourceData, $userInput);

        SolarisPromptLogger::log($prompt, $factories);

        $agent = $this->createAgent();
        $agent->configure($prompt, $factories);

        if ($this->pipelineTools !== null) {
            $agent->withTools($this->evaluate($this->pipelineTools));
        }

        $this->applyOptionsToAgent($agent);

        if ($agent instanceof ConversationalSolarisAgent && auth()->user() !== null) {
            $agent->forUser(auth()->user());
        }

        SolarisPromptLogger::logAgentSchema($agent);

        /** @var StructuredAgentResponse|null $response */
        $response = $this->executeAiCall(fn () => $agent->prompt($prompt, [], ...array_values($this->resolveAiCallParams())));

        if ($response === null) {
            return;
        }

        $aiResponse = $response->toArray();
        $conversationId = $response->conversationId ?? null;

        SolarisPromptLogger::logResponse($aiResponse, $conversationId);

        $this->applyResults($aiResponse, $factories, [
            'sourceData' => $sourceData,
            'userInput' => $userInput,
            'conversationId' => $conversationId,
        ]);
    }

    /**
     * Run the fake pipeline for testing.
     *
     * @param  array<string, mixed>  $sourceData
     * @param  array<string, mixed>  $userInput
     */
    protected function runFakePipeline(array $sourceData, array $userInput): void
    {
        [$prompt, $factories] = $this->buildPrompt($sourceData, $userInput);

        $fake = AiActionFake::getInstance();

        ['provider' => $provider, 'model' => $model] = $this->resolveProviderAndModel();
        $timeout = $this->resolveTimeout();
        $options = $this->resolveOptions();
        $fake->recordCall($this->getName(), $sourceData, $prompt, $provider, $model, $timeout, $options);

        if ($fake->shouldSimulateError()) {
            Notification::make()
                ->title($fake->getErrorMessage())
                ->danger()
                ->send();

            return;
        }

        if ($fake->shouldSimulateTimeout()) {
            Notification::make()
                ->title(filament_solaris_trans('notifications.timeout'))
                ->danger()
                ->send();

            return;
        }

        $aiResponse = $fake->resolve($this->getName());

        if ($aiResponse === null) {
            $aiResponse = [];
        }

        $this->applyResults($aiResponse, $factories, [
            'sourceData' => $sourceData,
            'userInput' => $userInput,
            'conversationId' => $this->isConversational() ? 'fake-conversation-id' : null,
        ]);
    }

    /**
     * Create the appropriate agent instance.
     */
    protected function createAgent(): SolarisAgent
    {
        if ($this->isConversational()) {
            return new ConversationalSolarisAgent;
        }

        return new SolarisAgent;
    }

    /**
     * Apply resolved text-generation options to the agent.
     */
    protected function applyOptionsToAgent(SolarisAgent $agent): void
    {
        $options = $this->resolveOptions();

        $agent
            ->withTemperature($options['temperature'])
            ->withMaxTokens($options['max_tokens'])
            ->withMaxSteps($options['max_steps'])
            ->withTopP($options['top_p']);
    }

    /**
     * Resolve the provider, model, and timeout for the AI call.
     *
     * @return array{provider: Lab|array|string|null, model: ?string, timeout: ?int}
     */
    protected function resolveAiCallParams(): array
    {
        return [
            ...$this->resolveProviderAndModel(),
            'timeout' => $this->resolveTimeout(),
        ];
    }

    /**
     * Execute an AI call with standardized error handling.
     *
     * Returns the response on success, or null if an AI exception occurred
     * (the error notification is sent automatically).
     *
     * @param  Closure(): AgentResponse  $callback
     */
    protected function executeAiCall(Closure $callback): ?AgentResponse
    {
        try {
            return $callback();
        } catch (AiException $e) {
            SolarisNotification::sendAiErrorNotification($e);

            return null;
        }
    }

    /**
     * Apply AI results to the form, or store for preview.
     *
     * @param  array<string, mixed>  $aiResponse
     * @param  array<string, ComponentFactory>  $factories
     * @param  array{sourceData?: array<string, mixed>, userInput?: array<string, mixed>, conversationId?: ?string}  $conversationalContext
     */
    protected function applyResults(
        array $aiResponse,
        array $factories,
        array $conversationalContext = [],
    ): void {
        $result = $this->transformResults($aiResponse, $factories);

        if ($this->shouldPreview() && ! empty($result['values'])) {
            $this->storePreviewData($result, $factories, $aiResponse, $conversationalContext);
            $this->halt();

            return; // halt() throws, but return for clarity
        }

        $this->writeResults($result['values'], $result['filledLabels'], $result['failedLabels']);
    }

    /**
     * Transform AI response values into form-ready values.
     *
     * @param  array<string, mixed>  $aiResponse
     * @param  array<string, ComponentFactory>  $factories
     * @return array{values: array<string, mixed>, filledLabels: array<string>, failedLabels: array<string>}
     */
    protected function transformResults(array $aiResponse, array $factories): array
    {
        $values = [];
        $filledLabels = [];
        $failedLabels = [];

        foreach ($factories as $fieldName => $factory) {
            if (! array_key_exists($fieldName, $aiResponse)) {
                $failedLabels[] = $this->resolveFieldLabel($fieldName);

                continue;
            }

            $aiValue = $aiResponse[$fieldName];

            if ($aiValue === null) {
                $failedLabels[] = $this->resolveFieldLabel($fieldName);

                continue;
            }

            try {
                $values[$fieldName] = $factory->toFormValue($aiValue);
                $filledLabels[] = $this->resolveFieldLabel($fieldName);
            } catch (\Throwable $e) {
                info($e);
                $failedLabels[] = $this->resolveFieldLabel($fieldName);
            }
        }

        return compact('values', 'filledLabels', 'failedLabels');
    }

    /**
     * Write transformed values to form fields and send notifications.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string>  $filledLabels
     * @param  array<string>  $failedLabels
     */
    protected function writeResults(array $values, array $filledLabels, array $failedLabels): void
    {
        $schemaComponent = $this->resolveFormSchemaComponent();

        if ($schemaComponent === null) {
            throw new \RuntimeException('Could not resolve a form schema component. Ensure the action is attached to a form field or the Livewire component has a "form" schema.');
        }

        $set = $schemaComponent
            ->makeSetUtility()
            ->skipComponentsChildContainersWhileSearching(false);

        foreach ($values as $fieldName => $formValue) {
            $set($fieldName, $formValue);
        }

        $this->sendResultNotifications($filledLabels, $failedLabels);
    }

    /**
     * Accept the preview and apply values to the form.
     *
     * Called by InteractsWithSolarisPreview when the user clicks "Accept".
     *
     * @param  array<string, mixed>  $data  The preview data stored on the Livewire component
     */
    public function acceptPreview(array $data): void
    {
        $this->writeResults($data['values'], $data['filledLabels'], $data['failedLabels']);
    }

    /**
     * Send appropriate notification based on filled/failed fields.
     *
     * @param  array<string>  $filledLabels
     * @param  array<string>  $failedLabels
     */
    protected function sendResultNotifications(array $filledLabels, array $failedLabels): void
    {
        SolarisNotification::sendResultNotifications($filledLabels, $failedLabels);
    }

    /**
     * Store preview data on the Livewire component for display in the preview modal.
     *
     * @param  array{values: array<string, mixed>, filledLabels: array<string>, failedLabels: array<string>}  $result
     * @param  array<string, ComponentFactory>  $factories
     * @param  array<string, mixed>  $aiResponse
     * @param  array{sourceData?: array<string, mixed>, userInput?: array<string, mixed>, conversationId?: ?string}  $conversationalContext
     */
    protected function storePreviewData(
        array $result,
        array $factories,
        array $aiResponse = [],
        array $conversationalContext = [],
    ): void {
        $livewire = $this->getLivewire();
        $displays = $this->buildDisplays($result['values'], $factories);

        $data = [
            'values' => $result['values'],
            'displays' => $displays,
            'filledLabels' => $result['filledLabels'],
            'failedLabels' => $result['failedLabels'],
            'actionName' => $this->getName(),
        ];

        if ($this->isConversational()) {
            $data['isConversational'] = true;
            $data['conversationId'] = $conversationalContext['conversationId'] ?? null;
            $data['sourceData'] = $conversationalContext['sourceData'] ?? [];
            $data['userInput'] = $conversationalContext['userInput'] ?? [];
            $data['messages'] = [
                [
                    'role' => 'assistant',
                    'content' => $aiResponse['_message'] ?? filament_solaris_trans('conversation.initial_message'),
                ],
            ];
        }

        $livewire->solarisPreviewData = $data;
    }

    /**
     * Build display arrays from values and factories.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, ComponentFactory>  $factories
     * @return array<string, array{label: string, display: string, type: string}>
     */
    protected function buildDisplays(array $values, array $factories): array
    {
        $displays = [];

        foreach ($values as $fieldName => $formValue) {
            $factory = $factories[$fieldName] ?? null;
            $preview = $factory?->toPreviewDisplay($formValue) ?? ['display' => (string) $formValue, 'type' => 'text'];

            $displays[$fieldName] = [
                'label' => $this->resolveFieldLabel($fieldName),
                'display' => $preview['display'],
                'type' => $preview['type'],
            ];
        }

        return $displays;
    }

    /**
     * Refine the preview results with a conversational follow-up message.
     */
    public function refine(string $message): void
    {
        $livewire = $this->getLivewire();
        $previewData = $livewire->solarisPreviewData;

        if ($previewData === null || ! ($previewData['isConversational'] ?? false)) {
            return;
        }

        // Append user message
        $previewData['messages'][] = ['role' => 'user', 'content' => $message];
        $livewire->solarisPreviewData = $previewData;

        if (AiActionFake::isActive()) {
            $this->runFakeRefinement($message, $previewData);

            return;
        }

        $this->runRefinement($message, $previewData);
    }

    /**
     * Run a real refinement call against the AI.
     *
     * @param  array<string, mixed>  $previewData
     */
    protected function runRefinement(string $message, array $previewData): void
    {
        $sourceData = $previewData['sourceData'] ?? [];
        $userInput = $previewData['userInput'] ?? [];

        [$prompt, $factories] = $this->buildPrompt($sourceData, $userInput);

        $agent = new ConversationalSolarisAgent;
        $agent->configure($prompt, $factories);

        $this->applyOptionsToAgent($agent);

        $user = auth()->user();

        if ($user !== null) {
            $agent->continue($previewData['conversationId'], $user);
        }

        SolarisPromptLogger::logAgentSchema($agent);

        /** @var StructuredAgentResponse|null $response */
        $response = $this->executeAiCall(fn () => $agent->prompt($message, [], ...array_values($this->resolveAiCallParams())));

        if ($response === null) {
            return;
        }

        $aiResponse = $response->toArray();

        SolarisPromptLogger::logResponse($aiResponse, $previewData['conversationId']);

        $result = $this->transformResults($aiResponse, $factories);
        $this->updatePreviewData($result, $factories, $aiResponse);
    }

    /**
     * Run a fake refinement call for testing.
     *
     * @param  array<string, mixed>  $previewData
     */
    protected function runFakeRefinement(string $message, array $previewData): void
    {
        $fake = AiActionFake::getInstance();
        $fake->recordRefinementCall($this->getName(), $message);

        $aiResponse = $fake->resolveRefinement($this->getName()) ?? [];

        $factories = $this->resolveTargetFactories();
        $result = $this->transformResults($aiResponse, $factories);
        $this->updatePreviewData($result, $factories, $aiResponse);
    }

    /**
     * Update the existing preview data with refined results.
     *
     * @param  array{values: array<string, mixed>, filledLabels: array<string>, failedLabels: array<string>}  $result
     * @param  array<string, ComponentFactory>  $factories
     * @param  array<string, mixed>  $aiResponse
     */
    protected function updatePreviewData(array $result, array $factories, array $aiResponse = []): void
    {
        $livewire = $this->getLivewire();
        $previewData = $livewire->solarisPreviewData;

        $previewData['values'] = $result['values'];
        $previewData['displays'] = $this->buildDisplays($result['values'], $factories);
        $previewData['filledLabels'] = $result['filledLabels'];
        $previewData['failedLabels'] = $result['failedLabels'];

        // Append assistant message
        $previewData['messages'][] = [
            'role' => 'assistant',
            'content' => $aiResponse['_message'] ?? filament_solaris_trans('conversation.refined_message'),
        ];

        $livewire->solarisPreviewData = $previewData;
    }

    /**
     * Resolve a human-readable label for a field name from the form schema.
     */
    protected function resolveFieldLabel(string $fieldName): string
    {
        try {
            $label = $this->getLivewire()
                ->getSchemaComponent("form.{$fieldName}")
                ?->getLabel();

            if (filled($label)) {
                return (string) $label;
            }
        } catch (\Throwable) {
            // Component not resolvable
        }

        return str($fieldName)->headline()->toString();
    }

    /**
     * Format a list of field labels for display in notifications.
     *
     * @param  array<string>  $labels
     */
    protected function formatFieldList(array $labels): string
    {
        return SolarisNotification::formatFieldList($labels);
    }

    /**
     * Resolve a form schema component for Get/Set utilities.
     */
    public function resolveFormSchemaComponent(): ?Component
    {
        $schemaComponent = $this->getSchemaComponent();

        if ($schemaComponent !== null) {
            return $schemaComponent;
        }

        $livewire = $this->getLivewire();
        $fieldName = $this->getFieldNamesForSchemaResolution()[0] ?? null;

        if ($fieldName === null) {
            return null;
        }

        $component = $livewire->getSchemaComponent("form.{$fieldName}");

        return $component instanceof Component ? $component : null;
    }

    /**
     * Resolve the current record, if available.
     */
    protected function resolveRecord(): ?Model
    {
        try {
            $livewire = $this->getLivewire();

            if (method_exists($livewire, 'getRecord')) {
                return $livewire->getRecord();
            }
        } catch (\Throwable) {
            // Not all Livewire components have getRecord()
        }

        return null;
    }

    /**
     * Get labels of target fields that already have values.
     *
     * @return array<string>
     */
    public function getFilledTargetFieldLabels(): array
    {
        $schemaComponent = $this->resolveFormSchemaComponent();

        if ($schemaComponent === null) {
            return [];
        }

        $get = $schemaComponent->makeGetUtility();
        $labels = [];

        foreach ($this->getTargetFields() as $field) {
            if (filled($get($field))) {
                $labels[] = $this->resolveFieldLabel($field);
            }
        }

        return $labels;
    }
}
