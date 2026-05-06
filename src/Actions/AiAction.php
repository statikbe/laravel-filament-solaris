<?php

namespace Statikbe\FilamentSolaris\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Statikbe\FilamentSolaris\Concerns\HasConversational;
use Statikbe\FilamentSolaris\Concerns\HasPreviewModal;
use Statikbe\FilamentSolaris\Concerns\HasPromptPipeline;
use Statikbe\FilamentSolaris\Concerns\HasSourceFields;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Statikbe\FilamentSolaris\Testing\AiActionFake;

class AiAction extends Action
{
    use HasConversational;
    use HasPreviewModal;
    use HasPromptPipeline;
    use HasSourceFields;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->icon(FilamentSolaris::config()->getActionIcon());

        $this->requiresConfirmation(function (AiAction $action): bool {
            if ($this->hasPreviewData()) {
                return false;
            }

            return ! empty($action->getFilledTargetFieldLabels());
        });

        $this->modalDescription(function (AiAction $action): ?string {
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

        $this->schema(function (AiAction $action): array {
            if ($this->hasPreviewData()) {
                return [];
            }

            return $action->getUserInputFormSchema();
        });

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
        if ($this->hasPreviewData()) {
            $this->halt();

            return;
        }

        $this->validateConfiguration();

        $userInput = $data;
        $sourceData = $this->getSourceFieldValues();

        if (AiActionFake::isActive()) {
            $this->runFakePipeline($sourceData, $userInput);

            return;
        }

        // Warn if source fields are configured but all empty
        if (! empty($this->getSourceFields()) && ! collect($sourceData)->contains(fn (mixed $value): bool => filled($value))) {
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
            throw new \RuntimeException('AiAction requires at least one target field.');
        }

        if ($this->promptBuilder === null) {
            throw new \RuntimeException('AiAction requires a prompt, preset, or custom promptBuilder.');
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
    public static function fake(array $response = []): AiActionFake
    {
        return AiActionFake::activate($response);
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
}
