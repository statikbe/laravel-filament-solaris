<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Statikbe\FilamentSolaris\Actions\SolarisAction;
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

/**
 * Text-generation pipeline plumbing for Solaris actions.
 *
 * Owns the bits that are specific to running a structured-response AI call:
 * PromptBuilder/Preset selection, locale, text-generation options
 * (temperature/maxTokens/maxSteps/topP/tools), preset-aware provider+timeout
 * resolution, factory-driven result transformation, conversational refinement.
 *
 * Form-side helpers (resolveFieldLabel, resolveFormSchemaComponent, etc.) come
 * from {@see HasFormPipeline}. Provider/timeout state, withPreview, and
 * executeAiCall come from {@see SolarisAction}.
 */
trait HasPromptPipeline
{
    use HasTargetFields;
    use HasUserInput;

    protected ?PromptBuilder $promptBuilder = null;

    protected string|View|null $promptInstruction = null;

    protected string|Closure|null $localeOverride = null;

    /** @var array<Tool|ProviderTool>|Closure|null */
    protected array|Closure|null $pipelineTools = null;

    protected float|int|Closure|null $pipelineTemperature = null;

    protected int|Closure|null $pipelineMaxTokens = null;

    protected int|Closure|null $pipelineMaxSteps = null;

    protected float|int|Closure|null $pipelineTopP = null;

    /**
     * Per-action sanitizer applied to every AI value before it's written to
     * form state. Receives the value returned by the factory's
     * {@see ComponentFactory::toFormValue()}; whatever it returns replaces
     * that value. Default: not set (identity).
     */
    protected ?Closure $sanitizer = null;

    /**
     * Per-field sanitizers, keyed by field name. Override the per-action
     * {@see $sanitizer} for that specific field.
     *
     * @var array<string, Closure>
     */
    protected array $fieldSanitizers = [];

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
     * Sanitize every AI value before it's written to form state.
     *
     * The closure receives the value returned by the factory's
     * `toFormValue()` and must return the sanitized value. Applied to
     * every target field unless overridden per-field via
     * {@see sanitizeField()}.
     *
     * Use this when the form is public-facing or AI-populated values
     * may later be rendered as raw HTML, included in emails, etc. — see
     * the "Security Considerations" section in the README.
     *
     * Example with HTML Purifier:
     *
     * ```php
     * AiAction::make('summarize')
     *     ->targetField('summary')
     *     ->sanitize(fn (string $value) => \Mews\Purifier\Facades\Purifier::clean($value));
     * ```
     *
     * @param  Closure(mixed): mixed  $closure
     */
    public function sanitize(Closure $closure): static
    {
        $this->sanitizer = $closure;

        return $this;
    }

    /**
     * Sanitize a single target field's AI value.
     *
     * Overrides any per-action {@see sanitize()} closure for this field
     * only. Use when different target fields need different sanitization
     * (e.g. `summary` stripped to plain text, `body_html` passed through
     * an HTML purifier).
     *
     * @param  Closure(mixed): mixed  $closure
     */
    public function sanitizeField(string $field, Closure $closure): static
    {
        $this->fieldSanitizers[$field] = $closure;

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
        $timeout = $this->resolveActionTimeout();

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
        $action = $this->resolveActionProvider();
        if ($action !== null) {
            return $action;
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

        $attachments = $this->resolveAttachments($userInput);

        $callParams = $this->resolveAiCallParams();

        /** @var StructuredAgentResponse|null $response */
        $response = $this->executeAiCall(
            fn () => $agent->prompt($prompt, $attachments, ...array_values($callParams)),
            $callParams['provider'],
            $callParams['model'],
        );

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
        $attachments = $this->resolveAttachments($userInput);
        $fake->recordCall($this->getName(), $sourceData, $prompt, $provider, $model, $timeout, $options, $attachments);

        if ($fake->shouldSimulateError()) {
            $this->dispatchFakeResponseFailed($fake->getErrorMessage(), $provider, $model);

            Notification::make()
                ->title($fake->getErrorMessage())
                ->danger()
                ->send();

            return;
        }

        if ($fake->shouldSimulateTimeout()) {
            $timeoutMessage = filament_solaris_trans('notifications.timeout');

            $this->dispatchFakeResponseFailed($timeoutMessage, $provider, $model);

            Notification::make()
                ->title($timeoutMessage)
                ->danger()
                ->send();

            return;
        }

        $aiResponse = $fake->resolve($this->getName()) ?? [];

        $this->dispatchFakeResponseReceived($provider, $model);

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
                $formValue = $factory->toFormValue($aiValue);
                $values[$fieldName] = $this->applySanitizer($fieldName, $formValue);
                $filledLabels[] = $this->resolveFieldLabel($fieldName);
            } catch (\Throwable $e) {
                // Route via report() so the app's exception tracker
                // (Sentry, Bugsnag, Flare, …) picks it up — info-level
                // logs are silently dropped by most production setups.
                report($e);
                $failedLabels[] = $this->resolveFieldLabel($fieldName);
            }
        }

        return compact('values', 'filledLabels', 'failedLabels');
    }

    /**
     * Apply the per-field or per-action sanitizer (if any) to a form value.
     *
     * Field-level closures registered via {@see sanitizeField()} take
     * precedence over the action-level {@see sanitize()} closure.
     */
    protected function applySanitizer(string $fieldName, mixed $formValue): mixed
    {
        if (isset($this->fieldSanitizers[$fieldName])) {
            return ($this->fieldSanitizers[$fieldName])($formValue);
        }

        if ($this->sanitizer !== null) {
            return ($this->sanitizer)($formValue);
        }

        return $formValue;
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
     * Refine the preview results with a conversational follow-up message.
     *
     * @param  array<string, mixed>  $turnAttachments  Files attached to this turn only,
     *                                                 in Livewire's `[uuid => TemporaryUploadedFile]` shape.
     */
    public function refine(string $message, array $turnAttachments = []): void
    {
        $livewire = $this->getLivewire();
        $previewData = $livewire->solarisPreviewData;

        if ($previewData === null || ! ($previewData['isConversational'] ?? false)) {
            return;
        }

        // Append user message (with attachment metadata for chat display)
        $previewData['messages'][] = [
            'role' => 'user',
            'content' => $message,
            'attachments' => $this->extractAttachmentMetadata($turnAttachments),
        ];
        $livewire->solarisPreviewData = $previewData;

        if (AiActionFake::isActive()) {
            $this->runFakeRefinement($message, $previewData, $turnAttachments);

            return;
        }

        $this->runRefinement($message, $previewData, $turnAttachments);
    }

    /**
     * Run a real refinement call against the AI.
     *
     * @param  array<string, mixed>  $previewData
     * @param  array<string, mixed>  $turnAttachments
     */
    protected function runRefinement(string $message, array $previewData, array $turnAttachments = []): void
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

        $attachments = $this->resolveAttachmentsForTurn($userInput, $turnAttachments);

        $callParams = $this->resolveAiCallParams();

        /** @var StructuredAgentResponse|null $response */
        $response = $this->executeAiCall(
            fn () => $agent->prompt($message, $attachments, ...array_values($callParams)),
            $callParams['provider'],
            $callParams['model'],
        );

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
     * @param  array<string, mixed>  $turnAttachments
     */
    protected function runFakeRefinement(string $message, array $previewData, array $turnAttachments = []): void
    {
        $fake = AiActionFake::getInstance();
        $attachments = $this->resolveAttachmentsForTurn($previewData['userInput'] ?? [], $turnAttachments);
        $fake->recordRefinementCall($this->getName(), $message, $attachments);

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
