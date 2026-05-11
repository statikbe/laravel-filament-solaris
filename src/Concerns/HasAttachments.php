<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Closure;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Files\File;
use Statikbe\FilamentSolaris\Factories\FileUploadFactory;
use Statikbe\FilamentSolaris\Support\ComponentFactoryResolver;

trait HasAttachments
{
    /** @var array<string>|Closure */
    protected array|Closure $attachmentFieldList = [];

    /** @var array<string>|Closure */
    protected array|Closure $attachmentUserInputKeyList = [];

    /** @var array<Closure> */
    protected array $attachmentClosures = [];

    /**
     * Bind one or more parent-form FileUpload fields as attachment sources.
     *
     * Accepts a literal field name, an array of field names, or a Closure
     * resolving to either at execution time. Replaces any previously set
     * value — pass an array to bind multiple fields in one call.
     */
    public function attachmentField(string|array|Closure $fields): static
    {
        $this->attachmentFieldList = $fields instanceof Closure || is_array($fields)
            ? $fields
            : [$fields];

        return $this;
    }

    /**
     * Bind one or more UserInput modal keys as attachment sources.
     *
     * Accepts a literal key, an array of keys, or a Closure resolving to
     * either at execution time. Replaces any previously set value.
     */
    public function attachmentFromUserInput(string|array|Closure $keys): static
    {
        $this->attachmentUserInputKeyList = $keys instanceof Closure || is_array($keys)
            ? $keys
            : [$keys];

        return $this;
    }

    /**
     * Supply attachments directly.
     *
     * Accepts:
     *   - a single `Files\File` instance (`Image::fromUrl(...)`, `Audio::fromPath(...)`, ...)
     *   - a single Laravel `UploadedFile` (e.g. `$request->file('upload')`) — auto-converted
     *   - an array mixing any of the above
     *   - a `Closure` returning any of the above
     *
     * Multiple calls accumulate — non-Closure values are wrapped internally so
     * every channel composes the same way.
     *
     * @param  File|UploadedFile|array<File|UploadedFile>|Closure  $resolver
     */
    public function attachments(File|UploadedFile|array|Closure $resolver): static
    {
        if (! ($resolver instanceof Closure)) {
            $value = $resolver;
            $resolver = fn () => $value;
        }

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

        foreach ($this->resolveFieldList($this->attachmentFieldList) as $field) {
            $factoryClass = $resolver->resolveFactoryClassForField($components, $field) ?? FileUploadFactory::class;
            $attachments = array_merge(
                $attachments,
                $factoryClass::toAttachments(data_get($formData, $field), $disk),
            );
        }

        foreach ($this->resolveFieldList($this->attachmentUserInputKeyList) as $key) {
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

            foreach (is_array($resolved) ? $resolved : [$resolved] as $item) {
                if ($item instanceof File) {
                    $attachments[] = $item;
                } elseif ($item instanceof UploadedFile) {
                    $attachments[] = FileUploadFactory::attachmentFromUpload($item);
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

    /**
     * Resolve the configured attachment channels plus the files supplied for
     * a single conversational refinement turn into a single `Files\File[]`.
     *
     * @param  array<string, mixed>  $userInput
     * @param  array<string, mixed>  $turnAttachments  Livewire `[uuid => TemporaryUploadedFile]` shape.
     * @return array<int, File>
     */
    protected function resolveAttachmentsForTurn(array $userInput, array $turnAttachments): array
    {
        return array_merge(
            $this->resolveAttachments($userInput),
            FileUploadFactory::toAttachments($turnAttachments, $this->resolveAttachmentDisk()),
        );
    }

    /**
     * Build display metadata (filenames) for chat-bubble attachment chips.
     *
     * UI-only concern: the returned shape is consumed by
     * `preview-conversational.blade.php` to render small chips inside the
     * user's message bubble. The persistent attachment channels live in
     * `resolveAttachments()`; this is purely for chat display.
     *
     * @param  array<string, mixed>  $turnAttachments
     * @return array<int, array{name: string}>
     */
    protected function extractAttachmentMetadata(array $turnAttachments): array
    {
        return array_values(array_map(
            fn ($file): array => ['name' => $file instanceof UploadedFile ? $file->getClientOriginalName() : 'file'],
            $turnAttachments,
        ));
    }

    /**
     * Resolve a stored field/key list (which may be an array, a Closure
     * returning a string or array, or a Closure returning null) into a flat
     * list of non-empty string names.
     *
     * @param  array<string>|Closure  $list
     * @return array<int, string>
     */
    private function resolveFieldList(array|Closure $list): array
    {
        $resolved = $list instanceof Closure ? $this->evaluate($list) : $list;

        if ($resolved === null) {
            return [];
        }

        return array_values(array_filter(
            (array) $resolved,
            fn ($name): bool => is_string($name) && filled($name),
        ));
    }
}
