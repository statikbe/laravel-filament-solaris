<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Closure;
use Laravel\Ai\Files\File;
use Statikbe\FilamentSolaris\Factories\FileUploadFactory;
use Statikbe\FilamentSolaris\Support\ComponentFactoryResolver;

trait HasAttachments
{
    /** @var array<string> */
    protected array $attachmentFieldList = [];

    /** @var array<string> */
    protected array $attachmentUserInputKeyList = [];

    /** @var array<Closure> */
    protected array $attachmentClosures = [];

    /**
     * Bind one or more parent-form FileUpload fields as attachment sources.
     */
    public function attachmentField(string|array $fields): static
    {
        $this->attachmentFieldList = array_merge(
            $this->attachmentFieldList,
            is_array($fields) ? $fields : [$fields],
        );

        return $this;
    }

    /**
     * Bind one or more UserInput modal keys as attachment sources.
     */
    public function attachmentFromUserInput(string|array $keys): static
    {
        $this->attachmentUserInputKeyList = array_merge(
            $this->attachmentUserInputKeyList,
            is_array($keys) ? $keys : [$keys],
        );

        return $this;
    }

    /**
     * Provide a Closure that returns laravel/ai Files\File instances directly.
     *
     * The closure receives the same evaluation context as other Filament closures
     * and may return any iterable of Files\File. Multiple calls accumulate.
     */
    public function attachments(Closure $resolver): static
    {
        $this->attachmentClosures[] = $resolver;

        return $this;
    }

    /**
     * Resolve all configured attachment channels into a single array of Files\File.
     *
     * Field-bound channels read directly from the Livewire component's form
     * state (not from `$sourceData`, which is filtered to source fields only),
     * so any FileUpload field can be referenced without also listing it as a
     * source field.
     *
     * @param  array<string, mixed>  $userInput
     * @return array<int, File>
     */
    protected function resolveAttachments(array $userInput): array
    {
        $disk = $this->resolveAttachmentDisk();
        $attachments = [];

        $livewire = $this->getLivewire();
        $formData = $livewire->data ?? [];

        $components = collect($livewire->getCachedSchemas())
            ->filter()
            ->flatMap(fn ($schema) => $schema->getFlatComponents())
            ->all();
        $resolver = app(ComponentFactoryResolver::class);

        foreach ($this->attachmentFieldList as $field) {
            $factoryClass = $resolver->resolveFactoryClassForField($components, $field) ?? FileUploadFactory::class;
            $attachments = array_merge(
                $attachments,
                $factoryClass::toAttachments(data_get($formData, $field), $disk),
            );
        }

        foreach ($this->attachmentUserInputKeyList as $key) {
            // UserInput modal fields aren't in the parent schema, so we can't
            // dispatch by component class for v1. Defaults to plain
            // FileUploadFactory; users with Spatie inside a UserInput modal
            // can supply a closure via attachments() instead.
            $attachments = array_merge(
                $attachments,
                FileUploadFactory::toAttachments($userInput[$key] ?? null, $disk),
            );
        }

        foreach ($this->attachmentClosures as $closure) {
            $resolved = $this->evaluate($closure);

            if ($resolved === null) {
                continue;
            }

            foreach ($resolved as $item) {
                if ($item instanceof File) {
                    $attachments[] = $item;
                }
            }
        }

        return $attachments;
    }

    /**
     * Resolve the disk used to dereference persisted attachment paths.
     *
     * Override in the consuming class to point at a different disk.
     * `HasImageGenerationPipeline` overrides this to reuse the action's
     * configured image storage disk.
     */
    protected function resolveAttachmentDisk(): ?string
    {
        return null;
    }
}
