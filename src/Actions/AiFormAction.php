<?php

namespace Statikbe\FilamentSolaris\Actions;

use Closure;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Statikbe\FilamentSolaris\Concerns\HasFormPipeline;
use Statikbe\FilamentSolaris\Concerns\HasPromptPipeline;
use Statikbe\FilamentSolaris\Concerns\HasSourceFields;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Statikbe\FilamentSolaris\Support\Metadata\ClosureFieldMetadata;
use Statikbe\FilamentSolaris\Support\Metadata\FieldMetadata;
use Statikbe\FilamentSolaris\Testing\AiFormActionFake;

class AiFormAction extends SolarisAction
{
    use HasFormPipeline;
    use HasPromptPipeline;
    use HasSourceFields;

    /** @var bool|array<int, string> */
    protected bool|array $selectablePreviewFields = false;

    protected ?FieldMetadata $fieldMetadata = null;

    protected ?string $fieldMetadataInstructions = null;

    protected ?Closure $afterFieldsWritten = null;

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
     * Let the user tick which fields the preview writes. Implies the preview.
     * `true` makes every target field selectable; an array limits selection to
     * those fields (the rest are always written); `false` turns selection off
     * and leaves the preview as configured.
     *
     * @param  bool|array<int, string>  $fields
     */
    public function withSelectablePreview(bool|array $fields = true): static
    {
        $this->selectablePreviewFields = $fields;

        if ($fields !== false) {
            $this->withPreview();
        }

        return $this;
    }

    /**
     * @return array<int, string>|null null when selection is off
     */
    public function getSelectablePreviewFields(): ?array
    {
        return match (true) {
            $this->selectablePreviewFields === false => null,
            $this->selectablePreviewFields === true => $this->getTargetFields(),
            default => array_values($this->selectablePreviewFields),
        };
    }

    /**
     * Ask the model for metadata beside every target field's value: a closure
     * returning the keys (`fn (JsonSchemaTypeFactory $s) => [...]`) or a
     * reusable FieldMetadata preset (`MyMetadata::make()`). null turns it off.
     */
    public function fieldMetadata(Closure|FieldMetadata|null $metadata): static
    {
        $this->fieldMetadata = $metadata instanceof Closure
            ? new ClosureFieldMetadata($metadata)
            : $metadata;

        return $this;
    }

    /**
     * Prompt language explaining the metadata keys. Overrides a preset's own
     * instructions().
     */
    public function fieldMetadataInstructions(?string $instructions): static
    {
        $this->fieldMetadataInstructions = $instructions;

        return $this;
    }

    /**
     * Called after fields are written to the form — on the direct path and on
     * preview Accept — with `$values` (what was written) and `$metadata`
     * (keyed by field; [] when metadata is off). Not called when nothing is written.
     */
    public function afterFieldsWritten(?Closure $callback): static
    {
        $this->afterFieldsWritten = $callback;

        return $this;
    }

    public function getFieldMetadata(): ?FieldMetadata
    {
        return $this->fieldMetadata;
    }

    public function getFieldMetadataInstructions(): ?string
    {
        return $this->fieldMetadataInstructions;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, array<string, mixed>>  $metadata
     */
    protected function callAfterFieldsWritten(array $values, array $metadata): void
    {
        if ($this->afterFieldsWritten === null) {
            return;
        }

        $this->evaluate($this->afterFieldsWritten, ['values' => $values, 'metadata' => $metadata]);
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

        // Fail before any AI call (and on the fake path) on a reserved key.
        $this->getFieldMetadata()?->resolveKeys(new JsonSchemaTypeFactory);

        $selectable = $this->getSelectablePreviewFields();

        if ($selectable === []) {
            throw new \RuntimeException('AiFormAction ->withSelectablePreview() needs at least one field: pass true for every target field, or false to turn selection off.');
        }

        $unknown = array_diff($selectable ?? [], $this->getTargetFields());

        if ($unknown !== []) {
            throw new \RuntimeException(sprintf(
                'AiFormAction ->withSelectablePreview() lists fields that are not target fields: %s.',
                implode(', ', $unknown),
            ));
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
    public static function assertCalledWith(Closure $callback): void
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
