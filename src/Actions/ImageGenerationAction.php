<?php

namespace Statikbe\FilamentSolaris\Actions;

use Statikbe\FilamentSolaris\Concerns\HasFormPipeline;
use Statikbe\FilamentSolaris\Concerns\HasImageGenerationPipeline;
use Statikbe\FilamentSolaris\Concerns\HasSourceFields;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Statikbe\FilamentSolaris\Testing\ImageGenerationActionFake;

class ImageGenerationAction extends SolarisAction
{
    use HasFormPipeline;
    use HasImageGenerationPipeline;
    use HasSourceFields;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->icon(FilamentSolaris::config()->getImageGenerationIcon());

        $this->modalHeading(fn () => filament_solaris_trans('image_generation.modal_heading'));

        $this->modalSubmitActionLabel(fn () => filament_solaris_trans('image_generation.submit_label'));

        $this->requiresConfirmation(function (ImageGenerationAction $action): bool {
            if ($this->hasPreviewData()) {
                return false;
            }

            return $action->targetFieldHasValue();
        });

        $this->modalDescription(function (ImageGenerationAction $action): ?string {
            if ($this->hasPreviewData()) {
                return null;
            }

            if (! $action->targetFieldHasValue()) {
                return null;
            }

            $label = $this->resolveFieldLabel($this->getTargetField());

            return filament_solaris_trans_choice('notifications.overwrite_warning', 1, [
                'fields' => "'{$label}'",
            ]);
        });

        $this->schema(function (ImageGenerationAction $action): array {
            if ($this->hasPreviewData()) {
                return [];
            }

            return $action->getUserInputFormSchema();
        });

        $this->action(function (ImageGenerationAction $action, array $data = []) {
            $action->execute($data);
        });
    }

    /**
     * Execute the image generation pipeline.
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

        $sourceData = $this->getSourceFieldValues();

        if ($this->warnIfSourceFieldsEmpty($sourceData)) {
            return;
        }

        if (ImageGenerationActionFake::isActive()) {
            $this->runFakeImagePipeline($sourceData, $data);

            return;
        }

        $this->runImagePipeline($sourceData, $data);
    }

    /**
     * Check if the target field already has a value.
     */
    protected function targetFieldHasValue(): bool
    {
        $schemaComponent = $this->resolveFormSchemaComponent();

        if ($schemaComponent === null) {
            return false;
        }

        $targetField = $this->getTargetField();

        if ($targetField === null) {
            return false;
        }

        $get = $schemaComponent->makeGetUtility();

        return filled($get($targetField));
    }

    /**
     * Validate the action configuration before execution.
     *
     * @throws \RuntimeException
     */
    private function validateConfiguration(): void
    {
        if ($this->getTargetField() === null) {
            throw new \RuntimeException('ImageGenerationAction requires a target field.');
        }

        if ($this->imagePromptInstruction === null) {
            throw new \RuntimeException('ImageGenerationAction requires a prompt.');
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Testing
    // ──────────────────────────────────────────────────────────────

    /**
     * Activate the fake for testing.
     */
    public static function fake(string $storedPath = 'ai-images/fake-image.png'): ImageGenerationActionFake
    {
        return ImageGenerationActionFake::activate($storedPath);
    }

    /**
     * Assert that an ImageGenerationAction was called.
     */
    public static function assertCalled(): void
    {
        ImageGenerationActionFake::getInstance()->assertCalled();
    }

    /**
     * Assert with a callback on the call data.
     */
    public static function assertCalledWith(\Closure $callback): void
    {
        ImageGenerationActionFake::getInstance()->assertCalledWith($callback);
    }

    /**
     * Assert that no ImageGenerationAction was called.
     */
    public static function assertNotCalled(): void
    {
        ImageGenerationActionFake::getInstance()->assertNotCalled();
    }

    /**
     * Assert the number of times an ImageGenerationAction was called.
     */
    public static function assertCalledTimes(int $count): void
    {
        ImageGenerationActionFake::getInstance()->assertCalledTimes($count);
    }
}
