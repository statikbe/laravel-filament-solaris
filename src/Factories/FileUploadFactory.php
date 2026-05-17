<?php

namespace Statikbe\FilamentSolaris\Factories;

use Filament\Schemas\Components\Component;
use Illuminate\Http\UploadedFile;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;
use Livewire\Component as LivewireComponent;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class FileUploadFactory extends ComponentFactory
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg', 'heic', 'avif'];

    private const AUDIO_EXTENSIONS = ['mp3', 'wav', 'm4a', 'ogg', 'flac', 'aac', 'opus'];

    /**
     * Convert FileUpload form-state value(s) into laravel/ai attachment objects.
     *
     * Handles three state shapes:
     *   - `[uuid => TemporaryUploadedFile, ...]` — fresh Livewire upload (single or multi)
     *   - `string` — a persisted filesystem path
     *   - `array<string>` — multiple persisted paths
     *
     * Type (Image / Audio / Document) is sniffed from MIME first, then extension.
     * Override this in a custom factory (e.g. for SpatieMediaLibraryFileUpload)
     * when the form-state shape diverges from the default Filament FileUpload.
     *
     * {@inheritDoc}
     *
     * FileUpload's live Livewire state is `[uuid => TemporaryUploadedFile]`
     * for fresh uploads and `string|array<string>` for persisted paths.
     * Filament's schema-based `Get` utility normalises this and loses the
     * `uuid => TempFile` mapping that {@see toAttachments()} relies on, so
     * we read straight from `$livewire->data`.
     */
    public static function readState(
        LivewireComponent $livewire,
        string $field,
        ?Component $schemaComponent = null,
    ): mixed {
        return data_get($livewire->data ?? [], $field);
    }

    /**
     * @return array<int, File>
     */
    public static function toAttachments(mixed $value, ?string $disk): array
    {
        if (blank($value)) {
            return [];
        }

        $attachments = [];

        if (is_array($value)) {
            foreach ($value as $entry) {
                if ($entry instanceof TemporaryUploadedFile) {
                    $attachments[] = self::attachmentFromUpload($entry);
                } elseif (is_string($entry) && filled($entry)) {
                    $attachments[] = self::attachmentFromStorage($entry, $disk);
                }
            }

            return $attachments;
        }

        if (is_string($value)) {
            $attachments[] = self::attachmentFromStorage($value, $disk);
        }

        return $attachments;
    }

    /**
     * Build a Base64-backed attachment from any Laravel UploadedFile.
     *
     * Public so `HasAttachments` can normalize bare `UploadedFile` /
     * `TemporaryUploadedFile` instances supplied via `->attachments(...)`.
     * Type (Image/Audio/Document) is resolved from MIME with extension fallback.
     */
    public static function attachmentFromUpload(UploadedFile $file): File
    {
        $mimeType = $file->getMimeType() ?: $file->getClientMimeType();
        $type = self::resolveAttachmentType($file->getClientOriginalName(), $mimeType);

        return match ($type) {
            Image::class => Image::fromUpload($file, $mimeType),
            Document::class => Document::fromUpload($file, $mimeType),
            // Audio has no fromUpload(); build a Base64Audio directly so the
            // base64 + mime resolution stays consistent across all three types.
            default => Audio::fromBase64(base64_encode($file->getContent()), $mimeType),
        };
    }

    /**
     * Build a Storage-disk-backed attachment from a persisted path.
     */
    private static function attachmentFromStorage(string $path, ?string $disk): File
    {
        return self::storedAttachmentFor(
            self::resolveAttachmentType($path, null),
            $path,
            $disk,
        );
    }

    /**
     * Build a Storage-disk-backed attachment for a pre-resolved type.
     *
     * Shared by the parent (which derives type from a path alone) and the
     * Spatie subclass (which has a stored MIME type to feed into the
     * resolver). Centralising the match keeps the Image/Audio/Document
     * dispatch in one place.
     *
     * @param  class-string<File>  $type
     */
    protected static function storedAttachmentFor(string $type, string $path, ?string $disk): File
    {
        return match ($type) {
            Image::class => Image::fromStorage($path, $disk),
            Audio::class => Audio::fromStorage($path, $disk),
            default => Document::fromStorage($path, $disk),
        };
    }

    /**
     * Resolve the laravel/ai Files\* class for an attachment from MIME type and filename.
     *
     * Specific MIME types (image/*, audio/*) win immediately. For generic or
     * unavailable MIMEs (application/octet-stream, null) the filename
     * extension is the tiebreaker — Spatie's fake-file storage and various
     * upload paths frequently produce a generic MIME for files whose
     * extension makes their type obvious.
     *
     * Anything still unresolved falls into Document — the most permissive
     * bucket — so unknown files attach gracefully and the upstream provider
     * decides whether to accept them.
     *
     * @return class-string<File>
     */
    public static function resolveAttachmentType(string $filename, ?string $mimeType): string
    {
        if ($mimeType !== null) {
            if (str_starts_with($mimeType, 'image/')) {
                return Image::class;
            }

            if (str_starts_with($mimeType, 'audio/')) {
                return Audio::class;
            }
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return Image::class;
        }

        if (in_array($extension, self::AUDIO_EXTENSIONS, true)) {
            return Audio::class;
        }

        return Document::class;
    }

    /**
     * {@inheritDoc}
     */
    public function responseSchema(JsonSchemaTypeFactory $schema): Type
    {
        return $schema->string()->description('File path');
    }

    /**
     * {@inheritDoc}
     */
    public function toFormValue(mixed $aiValue): mixed
    {
        return $aiValue;
    }

    /**
     * {@inheritDoc}
     *
     * Creates a Livewire TemporaryUploadedFile from the file content,
     * following the same pathway as a user upload. This ensures compatibility
     * with both FileUpload and SpatieMediaLibraryFileUpload components.
     *
     * @return array<string, TemporaryUploadedFile>
     */
    public function toFormValueFromFile(string $content, string $mimeType): mixed
    {
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
            default => 'png',
        };

        // Delegate the filename format (which embeds the original name so
        // getClientOriginalName() can round-trip it) to Livewire itself,
        // so we stay in sync if the encoding scheme changes upstream. We
        // wrap our raw bytes in a throwaway UploadedFile just to satisfy
        // the generator's expected interface — it only reads the original
        // name and extension off the file, not the path contents.
        $originalName = 'ai-generated-'.Str::random(8).'.'.$extension;
        $tmpPath = tempnam(sys_get_temp_dir(), 'solaris-ai-');
        file_put_contents($tmpPath, $content);

        try {
            $uploadedFile = new UploadedFile($tmpPath, $originalName, $mimeType, test: true);
            $filename = TemporaryUploadedFile::generateHashNameWithOriginalNameEmbedded($uploadedFile);
        } finally {
            @unlink($tmpPath);
        }

        $directory = FileUploadConfiguration::directory();
        $disk = FileUploadConfiguration::disk();
        $path = $directory.'/'.$filename;

        Storage::disk($disk)->put($path, $content, 'public');

        $tempFile = TemporaryUploadedFile::createFromLivewire($filename);

        $uuid = (string) Str::uuid();

        return [$uuid => $tempFile];
    }

    /**
     * {@inheritDoc}
     */
    public function toPromptContext(mixed $formValue): mixed
    {
        return $formValue;
    }

    /**
     * {@inheritDoc}
     *
     * Setting FilePond's state from PHP alone does not display a preview:
     * FilePond keeps its own internal file list and only renders files it
     * either uploaded itself or fetched via its `server.load` callback. So
     * after `$component->state([$uuid => $tempFile])` the file is correctly
     * staged in Livewire's entangled state (and will be persisted on save),
     * but the user sees an empty dropzone.
     *
     * The fix: fetch the Livewire preview URL from JS, wrap the response
     * in a real `File` object, then call `pond.addFile(file, { type:
     * 'local' })`. Two reasons we build our own File rather than letting
     * FilePond fetch the URL itself via `addFile(url, { type: 'local' })`:
     *
     *   1. FilePond's image-preview plugin only activates when it receives
     *      an actual File/Blob. Files that enter through `server.load(url,
     *      load)` display the filename placeholder but no thumbnail —
     *      browser-verified against Filament's FilePond build.
     *   2. We still get `type: 'local'` semantics (no re-upload via
     *      `server.process`), so the Livewire state we already populated
     *      server-side with `$component->state($formValue)` remains the
     *      source of truth on form save.
     *
     * Filament's `file-upload.js` `$watch('state')` handler sees the
     * `livewire-file:` prefix our `TemporaryUploadedFile` serialises to and
     * deliberately skips (file-upload.js:292), so there's no interference
     * between the two mechanisms.
     *
     * Element lookup uses `$component->getId()` which by default equals
     * `getKey()` (i.e. the state path with container inheritance prefix).
     * The file-upload blade renders that value as the `id` attribute on the
     * FilePond wrapper div, so `document.getElementById()` is the most
     * direct match — it respects custom `->id()` overrides and works
     * unchanged for deeply nested paths (repeaters, wizards, relation
     * managers) because inheritance keys guarantee uniqueness. We avoid the
     * wrapper's `wire:key` because Filament appends an md5 hash to it.
     *
     * Filament's `fileUploadFormComponent` Alpine component exposes the
     * FilePond instance as `this.pond`, so we can read it straight off the
     * wrapper's Alpine data stack (`element._x_dataStack[0].pond`) rather
     * than going through `FilePond.find(root)` — which would require both
     * a correct `.filepond--root` selector and FilePond being globally
     * registered at dispatch time. The Alpine path is what survives across
     * Filament versions and doesn't race with FilePond's own initialisation.
     */
    public function afterStateHydration(mixed $formValue, LivewireComponent $livewire): void
    {
        if (! is_array($formValue)) {
            return;
        }

        $tempFile = array_values($formValue)[0] ?? null;

        if (! $tempFile instanceof TemporaryUploadedFile) {
            return;
        }

        $elementId = $this->getComponent()->getId();

        if (blank($elementId)) {
            return;
        }

        $payload = [
            'elementId' => $elementId,
            'url' => $tempFile->temporaryUrl(),
            'name' => $tempFile->getClientOriginalName(),
            'size' => $tempFile->getSize(),
            'type' => $tempFile->getMimeType(),
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        // Executed in the browser after Livewire applies the state update.
        // We grab the FilePond instance from Filament's Alpine component
        // (exposed as `this.pond`) rather than via `FilePond.find()`, so
        // the lookup does not depend on FilePond being globally registered
        // at dispatch time or on the internal DOM class name staying the
        // same across versions.
        $livewire->js(<<<JS
            (async () => {
                const p = {$json};
                const wrapper = document.getElementById(p.elementId);
                if (! wrapper) return;
                const pond = wrapper._x_dataStack && wrapper._x_dataStack[0] && wrapper._x_dataStack[0].pond;
                if (! pond) return;
                try {
                    const response = await fetch(p.url, { cache: 'no-store' });
                    if (! response.ok) return;
                    const blob = await response.blob();
                    const file = new File([blob], p.name, { type: p.type });
                    await pond.addFile(file, { type: 'local' });
                } catch (e) {
                    console.error('[filament-solaris] failed to hydrate FilePond preview', e);
                }
            })();
        JS);
    }
}
