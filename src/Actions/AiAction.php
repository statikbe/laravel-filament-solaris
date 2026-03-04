<?php

namespace Statikbe\FilamentSolaris\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use RuntimeException;
use Statikbe\FilamentSolaris\Concerns\HasSourceFields;
use Statikbe\FilamentSolaris\Concerns\HasTargetFields;
use Statikbe\FilamentSolaris\Concerns\HasUserInput;
use Statikbe\FilamentSolaris\Contracts\PromptBuilder;
use Statikbe\FilamentSolaris\FilamentSolarisConfig;
use Statikbe\FilamentSolaris\Prompts\InlinePromptBuilder;
use Statikbe\FilamentSolaris\Prompts\Presets\Preset;
use Statikbe\FilamentSolaris\Prompts\ViewPromptBuilder;
use Statikbe\FilamentSolaris\Support\SolarisAgent;
use Statikbe\FilamentSolaris\Support\SolarisPromptLogger;
use Statikbe\FilamentSolaris\Testing\AiActionFake;

class AiAction extends Action
{
    use HasSourceFields;
    use HasTargetFields;
    use HasUserInput;

    protected ?PromptBuilder $promptBuilder = null;

    protected ?string $promptInstruction = null;

    protected string|\Closure|null $localeOverride = null;

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
    public function locale(string|\Closure $locale): static
    {
        $this->localeOverride = $locale;

        return $this;
    }

    public function getLocale(): string
    {
        return value($this->localeOverride) ?? app()->getLocale();
    }

    /**
     * Activate the fake for testing.
     *
     * @param  array<string, mixed>  $response
     */
    public static function fake(array $response = []): AiActionFake
    {
        return AiActionFake::activate($response);
    }

    /**
     * Assert that an AiAction was called.
     */
    public static function assertCalled(): void
    {
        AiActionFake::getInstance()->assertCalled();
    }

    /**
     * Assert with a callback on the call data.
     */
    public static function assertCalledWith(\Closure $callback): void
    {
        AiActionFake::getInstance()->assertCalledWith($callback);
    }

    /**
     * Assert that no AiAction was called.
     */
    public static function assertNotCalled(): void
    {
        AiActionFake::getInstance()->assertNotCalled();
    }

    /**
     * Assert the number of times an AiAction was called.
     */
    public static function assertCalledTimes(int $count): void
    {
        AiActionFake::getInstance()->assertCalledTimes($count);
    }

    /**
     * Simulate an API error.
     */
    public static function fakeError(string $message = 'AI service error'): AiActionFake
    {
        return AiActionFake::activateError($message);
    }

    /**
     * Simulate an API timeout.
     */
    public static function fakeTimeout(): AiActionFake
    {
        return AiActionFake::activateTimeout();
    }

    /**
     * Simulate partial failure.
     *
     * @param  array<string, mixed|null>  $response
     */
    public static function fakePartial(array $response): AiActionFake
    {
        return AiActionFake::activatePartial($response);
    }

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->icon(app(FilamentSolarisConfig::class)->getActionIcon());

        $this->requiresConfirmation(function (AiAction $action): bool {
            return ! empty($action->getFilledTargetFieldLabels());
        });

        $this->modalDescription(function (AiAction $action): ?string {
            $filledLabels = $action->getFilledTargetFieldLabels();

            if (empty($filledLabels)) {
                return null;
            }

            return filament_solaris_trans_choice('notifications.overwrite_warning', count($filledLabels), [
                'fields' => $this->formatFieldList($filledLabels),
            ]);
        });

        $this->schema(fn (AiAction $action): array => $action->getUserInputFormSchema());

        $this->action(function (AiAction $action, array $data = []) {
            $action->execute($data);
        });
    }

    /**
     * Execute the AI action pipeline.
     *
     * @param  array<string, mixed>  $data  Modal form data (user input)
     */
    public function execute(array $data = []): void
    {
        $this->validateConfiguration();

        $userInput = $data;

        // Step 1: Check if faked
        if (AiActionFake::isActive()) {
            $this->executeFake($userInput);

            return;
        }

        // Step 2: Collect source data and check if any are filled
        $sourceData = $this->getSourceFieldValues();

        if (! collect($sourceData)->contains(fn (mixed $value): bool => filled($value))) {
            $labels = array_map(
                fn (string $field): string => $this->resolveFieldLabel($field),
                $this->getSourceFields(),
            );

            Notification::make()
                ->title(filament_solaris_trans_choice('notifications.empty_source_fields', count($labels), [
                    'fields' => $this->formatFieldList($labels),
                ]))
                ->warning()
                ->send();

            return;
        }

        // Step 3: Resolve factories
        $factories = $this->resolveTargetFactories();

        // Step 4: Get record
        $record = $this->resolveRecord();

        // Step 5: Build prompt
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

        // Step 6: Log prompt if enabled
        SolarisPromptLogger::log($prompt, $factories);

        // Step 7: Call AI
        try {
            $agent = new SolarisAgent;
            $agent->configure($prompt, $factories);

            /** @var \Laravel\Ai\Responses\StructuredAgentResponse $response */
            $response = $agent->prompt($prompt);
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

        // Step 8: Transform and apply results
        $this->applyResults($aiResponse, $factories);
    }

    /**
     * Execute with fake responses.
     *
     * @param  array<string, mixed>  $userInput
     */
    private function executeFake(array $userInput): void
    {
        $sourceData = $this->getSourceFieldValues();
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

        // Record the call
        $fake->recordCall($this->getName(), $sourceData, $prompt);

        // Check for error simulation
        if ($fake->shouldSimulateError()) {
            Notification::make()
                ->title($fake->getErrorMessage())
                ->danger()
                ->send();

            return;
        }

        // Check for timeout simulation
        if ($fake->shouldSimulateTimeout()) {
            Notification::make()
                ->title(filament_solaris_trans('notifications.timeout'))
                ->danger()
                ->send();

            return;
        }

        // Get fake response
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
     * @param  array<string, \Statikbe\FilamentSolaris\Factories\ComponentFactory>  $factories
     */
    private function applyResults(array $aiResponse, array $factories): void
    {
        $schemaComponent = $this->resolveFormSchemaComponent();

        if ($schemaComponent === null) {
            throw new RuntimeException('AiAction could not resolve a form schema component. Ensure the action is attached to a form field or the Livewire component has a "form" schema.');
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
     * Resolve a human-readable label for a field name from the form schema,
     * falling back to a headline version of the name.
     */
    private function resolveFieldLabel(string $fieldName): string
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
     * Format a list of field labels for display in notifications:
     * wraps each in quotes and joins with commas and "&".
     *
     * @param  array<string>  $labels
     */
    private function formatFieldList(array $labels): string
    {
        $quoted = array_map(fn (string $label): string => "'{$label}'", $labels);

        return Arr::join($quoted, ', ', ' & ');
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

    /**
     * Resolve a form schema component for Get/Set utilities.
     *
     * When the action is attached to a form field (suffix/prefix action),
     * getSchemaComponent() returns that field. When used as a page-level
     * action, we fall back to looking up the first source field in the
     * Livewire component's "form" schema.
     */
    public function resolveFormSchemaComponent(): ?Component
    {
        $schemaComponent = $this->getSchemaComponent();

        if ($schemaComponent !== null) {
            return $schemaComponent;
        }

        $livewire = $this->getLivewire();
        $fieldName = $this->getSourceFields()[0] ?? $this->getTargetFields()[0] ?? null;

        if ($fieldName === null) {
            return null;
        }

        $component = $livewire->getSchemaComponent("form.{$fieldName}");

        return $component instanceof Component ? $component : null;
    }

    /**
     * Resolve the current record, if available.
     */
    private function resolveRecord(): ?\Illuminate\Database\Eloquent\Model
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
     * Validate the action configuration before execution.
     *
     * @throws RuntimeException
     */
    private function validateConfiguration(): void
    {
        if (empty($this->getSourceFields())) {
            throw new RuntimeException('AiAction requires at least one source field.');
        }

        if (empty($this->getTargetFields())) {
            throw new RuntimeException('AiAction requires at least one target field.');
        }

        if ($this->promptBuilder === null) {
            throw new RuntimeException('AiAction requires a prompt, preset, or custom promptBuilder.');
        }
    }
}
