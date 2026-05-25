<?php

namespace Statikbe\FilamentSolaris\Actions;

use Statikbe\FilamentSolaris\Concerns\HasFormPipeline;
use Statikbe\FilamentSolaris\Concerns\HasPromptPipeline;
use Statikbe\FilamentSolaris\Concerns\HasSourceFields;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Statikbe\FilamentSolaris\Testing\AiFormActionFake;

class AiFormAction extends SolarisAction
{
    use HasFormPipeline;
    use HasPromptPipeline;
    use HasSourceFields;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->icon(FilamentSolaris::config()->getActionIcon());

        $this->requiresConfirmation(function (AiFormAction $action): bool {
            if ($this->hasPreviewData()) {
                return false;
            }

            return ! empty($action->getFilledTargetFieldLabels());
        });

        $this->modalDescription(function (AiFormAction $action): ?string {
            if ($this->hasPreviewData()) {
                return null;
            }

            $filledLabels = $action->getFilledTargetFieldLabels();

            if (empty($filledLabels)) {
                return null;
            }

            return filament_solaris_trans_choice('notifications.overwrite_warning', count($filledLabels), [
                'fields' => $this->formatFieldList($filledLabels),
            ]);
        });

        $this->schema(function (AiFormAction $action): array {
            if ($this->hasPreviewData()) {
                return [];
            }

            return $action->getUserInputFormSchema();
        });

        $this->action(function (AiFormAction $action, array $data = []) {
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
        if ($this->hasPreviewData()) {
            $this->halt();

            return;
        }

        $this->validateConfiguration();
        $this->validatePreviewConfiguration();

        $userInput = $data;
        $sourceData = $this->getSourceFieldValues();

        if (AiFormActionFake::isActive()) {
            $this->runFakePipeline($sourceData, $userInput);

            return;
        }

        if ($this->warnIfSourceFieldsEmpty($sourceData)) {
            return;
        }

        $this->runPipeline($sourceData, $userInput);
    }

    /**
     * Include source fields in schema resolution lookup.
     *
     * @return array<string>
     */
    protected function getFieldNamesForSchemaResolution(): array
    {
        return array_merge($this->getSourceFields(), $this->getTargetFields());
    }

    /**
     * Validate the action configuration before execution.
     *
     * @throws \RuntimeException
     */
    private function validateConfiguration(): void
    {
        if (empty($this->getTargetFields())) {
            throw new \RuntimeException('AiFormAction requires at least one target field.');
        }

        if (! $this->hasPromptBuilder()) {
            throw new \RuntimeException('AiFormAction requires a prompt, preset, or custom promptBuilder.');
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Testing
    // ──────────────────────────────────────────────────────────────

    /**
     * Activate the fake for testing.
     *
     * @param  array<string, mixed>  $response
     */
    public static function fake(array $response = []): AiFormActionFake
    {
        return AiFormActionFake::activate($response);
    }

    /**
     * Simulate an API error.
     */
    public static function fakeError(string $message = 'AI service error'): AiFormActionFake
    {
        return AiFormActionFake::activateError($message);
    }

    /**
     * Simulate an API timeout.
     */
    public static function fakeTimeout(): AiFormActionFake
    {
        return AiFormActionFake::activateTimeout();
    }

    /**
     * Simulate partial failure.
     *
     * @param  array<string, mixed|null>  $response
     */
    public static function fakePartial(array $response): AiFormActionFake
    {
        return AiFormActionFake::activatePartial($response);
    }

    /**
     * Assert that an AiFormAction was called.
     */
    public static function assertCalled(): void
    {
        AiFormActionFake::getInstance()->assertCalled();
    }

    /**
     * Assert with a callback on the call data.
     */
    public static function assertCalledWith(\Closure $callback): void
    {
        AiFormActionFake::getInstance()->assertCalledWith($callback);
    }

    /**
     * Assert that no AiFormAction was called.
     */
    public static function assertNotCalled(): void
    {
        AiFormActionFake::getInstance()->assertNotCalled();
    }

    /**
     * Assert the number of times an AiFormAction was called.
     */
    public static function assertCalledTimes(int $count): void
    {
        AiFormActionFake::getInstance()->assertCalledTimes($count);
    }
}
