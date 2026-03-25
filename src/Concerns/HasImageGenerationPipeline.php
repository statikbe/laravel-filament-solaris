<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Closure;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Image;
use Laravel\Ai\Responses\ImageResponse;
use Statikbe\FilamentSolaris\Enums\ImageQuality;
use Statikbe\FilamentSolaris\Enums\ImageSize;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Statikbe\FilamentSolaris\Factories\ComponentFactory;
use Statikbe\FilamentSolaris\Support\ComponentFactoryResolver;
use Statikbe\FilamentSolaris\Support\SolarisNotification;
use Statikbe\FilamentSolaris\Support\SolarisPromptLogger;
use Statikbe\FilamentSolaris\Testing\ImageGenerationActionFake;

trait HasImageGenerationPipeline
{
    use HasUserInput;

    protected string|Closure|null $imagePromptInstruction = null;

    protected string|Closure|null $targetFieldName = null;

    protected ImageSize|string|Closure|null $imageSize = null;

    protected ImageQuality|string|Closure|null $imageQuality = null;

    protected string|Closure|null $storageDisk = null;

    protected string|Closure|null $storageDirectory = null;

    protected string|Closure|null $storageVisibility = null;

    /**
     * @var Lab|array<string, string>|array<int, string>|string|Closure|null
     */
    protected Lab|array|string|Closure|null $imageProvider = null;

    protected string|Closure|null $imageModel = null;

    protected int|Closure|null $imageTimeout = null;

    protected bool $imagePreview = false;

    /**
     * Enable the preview modal for image generation.
     *
     * When enabled, the generated image is shown in a preview modal
     * before being applied to the form. The image is held as base64
     * until the user accepts.
     */
    public function withPreview(bool $preview = true): static
    {
        $this->imagePreview = $preview;

        if ($preview) {
            $this->modal(true);
        }

        return $this;
    }

    /**
     * Check if preview mode is enabled.
     */
    public function shouldPreview(): bool
    {
        return $this->imagePreview;
    }

    /**
     * Set the prompt instruction for image generation.
     */
    public function prompt(string|Closure $instruction): static
    {
        $this->imagePromptInstruction = $instruction;

        return $this;
    }

    /**
     * Set the target FileUpload field name.
     */
    public function targetField(string|Closure $field): static
    {
        $this->targetFieldName = $field;

        return $this;
    }

    /**
     * Get the resolved target field name.
     */
    public function getTargetField(): ?string
    {
        return $this->evaluate($this->targetFieldName);
    }

    /**
     * Set the image size/aspect ratio.
     *
     * Accepts ImageSize enum, ratio strings ('1:1', '3:2', '2:3'),
     * or convenience aliases ('square', 'portrait', 'landscape').
     */
    public function imageSize(ImageSize|string|Closure $size): static
    {
        $this->imageSize = $size;

        return $this;
    }

    /**
     * Set the image quality.
     *
     * Accepts ImageQuality enum or strings ('low', 'medium', 'high').
     */
    public function imageQuality(ImageQuality|string|Closure $quality): static
    {
        $this->imageQuality = $quality;

        return $this;
    }

    /**
     * Set the storage disk.
     */
    public function disk(string|Closure $disk): static
    {
        $this->storageDisk = $disk;

        return $this;
    }

    /**
     * Set the storage directory.
     */
    public function directory(string|Closure $directory): static
    {
        $this->storageDirectory = $directory;

        return $this;
    }

    /**
     * Set the storage visibility ('public' or null).
     */
    public function visibility(string|Closure $visibility): static
    {
        $this->storageVisibility = $visibility;

        return $this;
    }

    /**
     * Set the AI provider (and optionally model) for image generation.
     *
     * @param  Lab|array<string, string>|array<int, string>|string|Closure  $provider
     */
    public function provider(Lab|array|string|Closure $provider, string|Closure|null $model = null): static
    {
        $this->imageProvider = $provider;

        if ($model !== null) {
            $this->imageModel = $model;
        }

        return $this;
    }

    /**
     * Set the timeout in seconds for the image generation call.
     */
    public function timeout(int|Closure $timeout): static
    {
        $this->imageTimeout = $timeout;

        return $this;
    }

    /**
     * Resolve the provider and model for image generation.
     *
     * Resolution chain: action → config → null (laravel/ai default).
     *
     * @return array{provider: Lab|array|string|null, model: ?string}
     */
    protected function resolveImageProviderAndModel(): array
    {
        $provider = $this->evaluate($this->imageProvider);
        if ($provider !== null) {
            return [
                'provider' => $provider,
                'model' => $this->evaluate($this->imageModel),
            ];
        }

        $config = FilamentSolaris::config();

        return [
            'provider' => $config->getDefaultImageProvider(),
            'model' => $config->getDefaultImageModel(),
        ];
    }

    /**
     * Resolve the timeout for image generation.
     */
    protected function resolveImageTimeout(): ?int
    {
        $timeout = $this->evaluate($this->imageTimeout);

        if ($timeout !== null) {
            return $timeout;
        }

        return FilamentSolaris::config()->getDefaultImageTimeout();
    }

    /**
     * Resolve the image size, normalizing enums and convenience aliases.
     */
    protected function resolveImageSize(): ?string
    {
        $size = $this->evaluate($this->imageSize)
            ?? FilamentSolaris::config()->getDefaultImageSize();

        if ($size === null) {
            return null;
        }

        if ($size instanceof ImageSize) {
            return $size->value;
        }

        return match ($size) {
            'square' => '1:1',
            'portrait' => '2:3',
            'landscape' => '3:2',
            default => $size,
        };
    }

    /**
     * Resolve the image quality, normalizing enums.
     */
    protected function resolveImageQuality(): ?string
    {
        $quality = $this->evaluate($this->imageQuality)
            ?? FilamentSolaris::config()->getDefaultImageQuality();

        if ($quality instanceof ImageQuality) {
            return $quality->value;
        }

        return $quality;
    }

    /**
     * Resolve the storage disk.
     */
    protected function resolveStorageDisk(): ?string
    {
        return $this->evaluate($this->storageDisk)
            ?? FilamentSolaris::config()->getDefaultImageDisk();
    }

    /**
     * Resolve the storage directory.
     */
    protected function resolveStorageDirectory(): string
    {
        return $this->evaluate($this->storageDirectory)
            ?? FilamentSolaris::config()->getDefaultImageDirectory();
    }

    /**
     * Resolve the storage visibility.
     */
    protected function resolveStorageVisibility(): ?string
    {
        return $this->evaluate($this->storageVisibility)
            ?? FilamentSolaris::config()->getDefaultImageVisibility();
    }

    /**
     * Compose the final prompt string from instruction, source data, and user input.
     *
     * @param  array<string, mixed>  $sourceData
     * @param  array<string, mixed>  $userInput
     */
    protected function composePrompt(array $sourceData, array $userInput): string
    {
        $parts = [];

        $instruction = $this->evaluate($this->imagePromptInstruction) ?? '';
        if (filled($instruction)) {
            $parts[] = $instruction;
        }

        $contextLines = [];
        foreach ($sourceData as $field => $value) {
            if (filled($value)) {
                $label = $this->resolveFieldLabel($field);
                $contextLines[] = "- {$label}: {$value}";
            }
        }

        if (! empty($contextLines)) {
            $parts[] = "Context:\n".implode("\n", $contextLines);
        }

        $additionalInstructions = $userInput['additional_instructions'] ?? null;
        if (filled($additionalInstructions)) {
            $parts[] = $additionalInstructions;
        }

        return implode("\n\n", $parts);
    }

    /**
     * Generate an image via the laravel/ai Image API.
     */
    protected function generateImage(string $prompt): ?ImageResponse
    {
        try {
            $pending = Image::of($prompt);

            $size = $this->resolveImageSize();
            if ($size !== null) {
                $pending->size($size);
            }

            $quality = $this->resolveImageQuality();
            if ($quality !== null) {
                $pending->quality($quality);
            }

            $timeout = $this->resolveImageTimeout();
            if ($timeout !== null) {
                $pending->timeout($timeout);
            }

            ['provider' => $provider, 'model' => $model] = $this->resolveImageProviderAndModel();

            SolarisPromptLogger::logImagePrompt($prompt, $size, $quality, $provider, $model);

            $response = $pending->generate($provider, $model);

            SolarisPromptLogger::logImageResponse(
                $response->count(),
                $response->firstImage()->mime,
            );

            return $response;
        } catch (AiException $e) {
            SolarisNotification::sendImageGenerationErrorNotification($e);

            return null;
        }
    }

    /**
     * Write the generated image to the target field using the component's factory.
     *
     * Resolves the ComponentFactory for the target field via the existing
     * factory map and calls toFormValueFromFile() to get the appropriate
     * form state value.
     */
    protected function writeImageToField(ImageResponse $response): void
    {
        $targetField = $this->getTargetField();
        $factory = $this->resolveTargetFactory();

        SolarisPromptLogger::logImageWrite(
            get_class($factory),
            $targetField,
        );

        $image = $response->firstImage();
        $formValue = $factory->toFormValueFromFile(
            $image->content(),
            $image->mime ?? 'image/png',
        );

        $factory->getComponent()->state($formValue);
    }

    /**
     * Resolve the ComponentFactory for the target field.
     */
    protected function resolveTargetFactory(): ComponentFactory
    {
        $livewire = $this->getLivewire();

        $components = collect($livewire->getCachedSchemas())
            ->filter()
            ->flatMap(fn ($schema) => $schema->getFlatComponents())
            ->all();

        $resolver = app(ComponentFactoryResolver::class);

        return $resolver->resolve($components, $this->getTargetField());
    }

    /**
     * Resolve a form schema component for the target field.
     */
    protected function resolveImageFormSchemaComponent(): ?Component
    {
        $schemaComponent = $this->getSchemaComponent();

        if ($schemaComponent !== null) {
            return $schemaComponent;
        }

        $livewire = $this->getLivewire();
        $targetField = $this->getTargetField();

        if ($targetField === null) {
            return null;
        }

        $component = $livewire->getSchemaComponent("form.{$targetField}");

        return $component instanceof Component ? $component : null;
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
     * Run the fake pipeline for testing.
     *
     * @param  array<string, mixed>  $sourceData
     * @param  array<string, mixed>  $userInput
     */
    protected function runFakeImagePipeline(array $sourceData, array $userInput): void
    {
        $prompt = $this->composePrompt($sourceData, $userInput);

        $fake = ImageGenerationActionFake::getInstance();

        ['provider' => $provider, 'model' => $model] = $this->resolveImageProviderAndModel();

        $fake->recordCall(
            $this->getName(),
            $prompt,
            $this->resolveImageSize(),
            $this->resolveImageQuality(),
            $provider,
            $model,
            $this->resolveImageTimeout(),
            $this->resolveStorageDisk(),
            $this->resolveStorageDirectory(),
        );

        if ($this->shouldPreview()) {
            $this->storeFakeImagePreviewData($prompt, $sourceData, $userInput);
            $this->halt();

            return;
        }

        $this->writeFakeImageToField($fake->getStoredPath());
        $this->sendImageSuccessNotification();
    }

    /**
     * Run the real image generation pipeline.
     *
     * @param  array<string, mixed>  $sourceData
     * @param  array<string, mixed>  $userInput
     */
    protected function runImagePipeline(array $sourceData, array $userInput): void
    {
        $prompt = $this->composePrompt($sourceData, $userInput);

        $response = $this->generateImage($prompt);

        if ($response === null) {
            return;
        }

        if ($this->shouldPreview()) {
            $this->storeImagePreviewData($response, $prompt, $sourceData, $userInput);
            $this->halt();

            return;
        }

        $this->writeImageToField($response);
        $this->sendImageSuccessNotification();
    }

    /**
     * Write a fake image path to the target field (for testing).
     */
    protected function writeFakeImageToField(string $storedPath): void
    {
        $schemaComponent = $this->resolveImageFormSchemaComponent();

        if ($schemaComponent === null) {
            throw new \RuntimeException('ImageGenerationAction could not resolve a form schema component.');
        }

        $set = $schemaComponent
            ->makeSetUtility()
            ->skipComponentsChildContainersWhileSearching(false);

        $set($this->getTargetField(), $storedPath);
    }

    /**
     * Store image preview data on the Livewire component for display in the preview modal.
     *
     * The image is held as base64 — not stored to disk — until the user accepts.
     *
     * @param  array<string, mixed>  $sourceData
     * @param  array<string, mixed>  $userInput
     */
    protected function storeImagePreviewData(
        ImageResponse $response,
        string $prompt,
        array $sourceData,
        array $userInput,
    ): void {
        $image = $response->firstImage();
        $mime = $image->mime ?? 'image/png';
        $dataUri = 'data:'.$mime.';base64,'.$image->image;

        $targetField = $this->getTargetField();
        $label = $this->resolveFieldLabel($targetField);

        $data = [
            'values' => [$targetField => null],
            'displays' => [
                $targetField => [
                    'label' => $label,
                    'display' => $dataUri,
                    'type' => 'image',
                ],
            ],
            'filledLabels' => [$label],
            'failedLabels' => [],
            'actionName' => $this->getName(),
            'imageBase64' => $image->image,
            'imageMime' => $mime,
            'originalPrompt' => $prompt,
            'sourceData' => $sourceData,
            'userInput' => $userInput,
        ];

        if ($this->isConversational()) {
            $data['isConversational'] = true;
            $data['messages'] = [
                ['role' => 'assistant', 'content' => filament_solaris_trans('conversation.initial_message')],
            ];
        }

        $this->getLivewire()->solarisPreviewData = $data;
    }

    /**
     * Store fake image preview data for testing.
     *
     * @param  array<string, mixed>  $sourceData
     * @param  array<string, mixed>  $userInput
     */
    protected function storeFakeImagePreviewData(string $prompt, array $sourceData, array $userInput): void
    {
        $targetField = $this->getTargetField();
        $label = $this->resolveFieldLabel($targetField);

        $data = [
            'values' => [$targetField => null],
            'displays' => [
                $targetField => [
                    'label' => $label,
                    'display' => 'data:image/png;base64,fake',
                    'type' => 'image',
                ],
            ],
            'filledLabels' => [$label],
            'failedLabels' => [],
            'actionName' => $this->getName(),
            'imageBase64' => 'fake',
            'imageMime' => 'image/png',
            'originalPrompt' => $prompt,
            'sourceData' => $sourceData,
            'userInput' => $userInput,
        ];

        if ($this->isConversational()) {
            $data['isConversational'] = true;
            $data['messages'] = [
                ['role' => 'assistant', 'content' => filament_solaris_trans('conversation.initial_message')],
            ];
        }

        $this->getLivewire()->solarisPreviewData = $data;
    }

    /**
     * Accept the preview and apply the image to the form.
     *
     * Called by InteractsWithSolarisPreview when the user clicks "Accept".
     * Stores the base64 image via the factory and writes to the target field.
     *
     * @param  array<string, mixed>  $data  The preview data stored on the Livewire component
     */
    public function acceptPreview(array $data): void
    {
        $factory = $this->resolveTargetFactory();
        $formValue = $factory->toFormValueFromFile(
            base64_decode($data['imageBase64']),
            $data['imageMime'] ?? 'image/png',
        );

        $factory->getComponent()->state($formValue);
        $this->sendImageSuccessNotification();
    }

    /**
     * Refine the preview image with a conversational follow-up message.
     *
     * Re-generates the image with the feedback appended to the original prompt.
     * Image APIs are stateless — no conversation agent needed.
     */
    public function refine(string $message): void
    {
        $livewire = $this->getLivewire();
        $previewData = $livewire->solarisPreviewData;

        if ($previewData === null || ! ($previewData['isConversational'] ?? false)) {
            return;
        }

        $previewData['messages'][] = ['role' => 'user', 'content' => $message];
        $livewire->solarisPreviewData = $previewData;

        if (ImageGenerationActionFake::isActive()) {
            $this->runFakeImageRefinement($message, $previewData);

            return;
        }

        $this->runImageRefinement($message, $previewData);
    }

    /**
     * Run a real image refinement — re-generate with feedback.
     *
     * @param  array<string, mixed>  $previewData
     */
    protected function runImageRefinement(string $message, array $previewData): void
    {
        $refinedPrompt = $previewData['originalPrompt']."\n\nFeedback: ".$message;

        $response = $this->generateImage($refinedPrompt);

        if ($response === null) {
            return;
        }

        $this->updateImagePreviewData($response, $refinedPrompt);
    }

    /**
     * Run a fake image refinement for testing.
     *
     * @param  array<string, mixed>  $previewData
     */
    protected function runFakeImageRefinement(string $message, array $previewData): void
    {
        $fake = ImageGenerationActionFake::getInstance();
        $fake->recordRefinementCall($this->getName(), $message);

        $livewire = $this->getLivewire();
        $previewData = $livewire->solarisPreviewData;

        $previewData['originalPrompt'] = $previewData['originalPrompt']."\n\nFeedback: ".$message;

        $previewData['messages'][] = [
            'role' => 'assistant',
            'content' => filament_solaris_trans('conversation.refined_message'),
        ];

        $livewire->solarisPreviewData = $previewData;
    }

    /**
     * Update the preview data with a newly generated image.
     */
    protected function updateImagePreviewData(ImageResponse $response, string $refinedPrompt): void
    {
        $livewire = $this->getLivewire();
        $previewData = $livewire->solarisPreviewData;

        $image = $response->firstImage();
        $mime = $image->mime ?? 'image/png';
        $dataUri = 'data:'.$mime.';base64,'.$image->image;

        $targetField = $this->getTargetField();

        $previewData['displays'][$targetField]['display'] = $dataUri;
        $previewData['imageBase64'] = $image->image;
        $previewData['imageMime'] = $mime;
        $previewData['originalPrompt'] = $refinedPrompt;

        $previewData['messages'][] = [
            'role' => 'assistant',
            'content' => filament_solaris_trans('conversation.refined_message'),
        ];

        $livewire->solarisPreviewData = $previewData;
    }

    /**
     * Send a success notification for image generation.
     */
    protected function sendImageSuccessNotification(): void
    {
        $targetLabel = $this->resolveFieldLabel($this->getTargetField());

        Notification::make()
            ->title(filament_solaris_trans('notifications.image_generation_success', [
                'fields' => "'{$targetLabel}'",
            ]))
            ->success()
            ->send();
    }
}
