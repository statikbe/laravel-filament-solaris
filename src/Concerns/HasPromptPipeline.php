<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Closure;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Statikbe\FilamentSolaris\Contracts\PromptBuilder;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Statikbe\FilamentSolaris\Factories\ComponentFactory;
use Statikbe\FilamentSolaris\Prompts\InlinePromptBuilder;
use Statikbe\FilamentSolaris\Prompts\Presets\Preset;
use Statikbe\FilamentSolaris\Prompts\ViewPromptBuilder;
use Statikbe\FilamentSolaris\Support\SolarisAgent;
use Statikbe\FilamentSolaris\Support\SolarisPromptLogger;
use Statikbe\FilamentSolaris\Testing\AiActionFake;

trait HasPromptPipeline
{
    use HasTargetFields;
    use HasUserInput;

    protected ?PromptBuilder $promptBuilder = null;

    protected ?string $promptInstruction = null;

    protected string|Closure|null $localeOverride = null;

    /**
     * @var Lab|array<string, string>|array<int, string>|string|Closure|null
     */
    protected Lab|array|string|Closure|null $pipelineProvider = null;

    protected string|Closure|null $pipelineModel = null;

    protected int|Closure|null $pipelineTimeout = null;

    /**
     * Set an inline prompt instruction or a Blade view.
     */
    public function prompt(string|View $instruction): static
    {
        if ($instruction instanceof View) {
            $this->promptBuilder = new ViewPromptBuilder;
            $this->promptInstruction = null;
        } else {
            $this->promptBuilder = new InlinePromptBuilder;
            $this->promptInstruction = $instruction;
        }

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
            $config = FilamentSolaris::config();
            $presetConfig = $config->getPresetProvider(get_class($this->promptBuilder));
            if ($presetConfig['provider'] !== null) {
                return $presetConfig;
            }
        }

        // 4. Config default_provider / default_model
        $config = FilamentSolaris::config();

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
     * Run the AI pipeline with the given source data and user input.
     *
     * @param  array<string, mixed>  $sourceData
     * @param  array<string, mixed>  $userInput
     */
    protected function runPipeline(array $sourceData, array $userInput): void
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

        SolarisPromptLogger::log($prompt, $factories);

        try {
            $agent = new SolarisAgent;
            $agent->configure($prompt, $factories);

            ['provider' => $provider, 'model' => $model] = $this->resolveProviderAndModel();
            $timeout = $this->resolveTimeout();

            /** @var StructuredAgentResponse $response */
            $response = $agent->prompt($prompt, [], $provider, $model, $timeout);
            $aiResponse = $response->toArray();
        } catch (RateLimitedException $e) {
            report($e);
            Notification::make()
                ->title(filament_solaris_trans('notifications.rate_limited'))
                ->danger()
                ->send();

            return;
        } catch (ProviderOverloadedException $e) {
            report($e);
            Notification::make()
                ->title(filament_solaris_trans('notifications.overloaded'))
                ->danger()
                ->send();

            return;
        } catch (AiException $e) {
            report($e);
            Notification::make()
                ->title(filament_solaris_trans('notifications.error'))
                ->danger()
                ->send();

            return;
        }

        $this->applyResults($aiResponse, $factories);
    }

    /**
     * Run the fake pipeline for testing.
     *
     * @param  array<string, mixed>  $sourceData
     * @param  array<string, mixed>  $userInput
     */
    protected function runFakePipeline(array $sourceData, array $userInput): void
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

        $fake = AiActionFake::getInstance();

        ['provider' => $provider, 'model' => $model] = $this->resolveProviderAndModel();
        $timeout = $this->resolveTimeout();
        $fake->recordCall($this->getName(), $sourceData, $prompt, $provider, $model, $timeout);

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

        $this->applyResults($aiResponse, $factories);
    }

    /**
     * Apply AI results to the form.
     *
     * @param  array<string, mixed>  $aiResponse
     * @param  array<string, ComponentFactory>  $factories
     */
    protected function applyResults(array $aiResponse, array $factories): void
    {
        $schemaComponent = $this->resolveFormSchemaComponent();

        if ($schemaComponent === null) {
            throw new \RuntimeException('Could not resolve a form schema component. Ensure the action is attached to a form field or the Livewire component has a "form" schema.');
        }

        $set = $schemaComponent
            ->makeSetUtility()
            ->skipComponentsChildContainersWhileSearching(false);
        $filledFields = [];
        $failedFields = [];

        foreach ($factories as $fieldName => $factory) {
            if (! array_key_exists($fieldName, $aiResponse)) {
                $failedFields[] = $this->resolveFieldLabel($fieldName);

                continue;
            }

            $aiValue = $aiResponse[$fieldName];

            if ($aiValue === null) {
                $failedFields[] = $this->resolveFieldLabel($fieldName);

                continue;
            }

            try {
                $formValue = $factory->toFormValue($aiValue);
                $set($fieldName, $formValue);
                $filledFields[] = $this->resolveFieldLabel($fieldName);
            } catch (\Throwable $e) {
                info($e);
                $failedFields[] = $this->resolveFieldLabel($fieldName);
            }
        }

        if (! empty($filledFields) && empty($failedFields)) {
            Notification::make()
                ->title(filament_solaris_trans('notifications.success', ['fields' => $this->formatFieldList($filledFields)]))
                ->success()
                ->send();
        } elseif (! empty($filledFields) && ! empty($failedFields)) {
            Notification::make()
                ->title(filament_solaris_trans_choice('notifications.partial_failure', count($failedFields), ['fields' => $this->formatFieldList($failedFields)]))
                ->warning()
                ->send();
        } else {
            Notification::make()
                ->title(filament_solaris_trans('notifications.error'))
                ->danger()
                ->send();
        }
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
        $quoted = array_map(fn (string $label): string => "'{$label}'", $labels);

        return Arr::join($quoted, ', ', ' & ');
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
     * Validate that the pipeline is properly configured.
     *
     * @throws \RuntimeException
     */
    protected function validatePipelineConfiguration(): void
    {
        if (empty($this->getTargetFields())) {
            throw new \RuntimeException(static::class.' requires at least one target field.');
        }

        if ($this->promptBuilder === null) {
            throw new \RuntimeException(static::class.' requires a prompt, preset, or custom promptBuilder.');
        }
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
