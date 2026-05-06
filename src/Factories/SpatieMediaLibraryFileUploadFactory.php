<?php

namespace Statikbe\FilamentSolaris\Factories;

use Laravel\Ai\Files\File;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Attachment-resolution variant of FileUploadFactory for Filament's
 * `SpatieMediaLibraryFileUpload` component. Inherits all other factory
 * behaviour (response schema, write-back) from the parent for v1.
 *
 * Spatie symbols are referenced lazily inside method bodies and gated on
 * `class_exists()`, so the package autoloads cleanly even when Spatie's
 * media-library isn't installed.
 */
class SpatieMediaLibraryFileUploadFactory extends FileUploadFactory
{
    /**
     * {@inheritDoc}
     *
     * Handles Spatie's post-mount state shape on top of the parent's
     * `[uuid => TemporaryUploadedFile]` (fresh upload) and `string`/`string[]`
     * (persisted path) cases. The Spatie shape is `[mediaUuid => mediaUuid]`
     * — both key and value are the Spatie Media model's UUID, set by
     * `SpatieMediaLibraryFileUpload::loadStateFromRelationshipsUsing`.
     *
     * @return array<int, File>
     */
    public static function toAttachments(mixed $value, ?string $disk): array
    {
        if (blank($value)) {
            return [];
        }

        if (is_array($value) && self::looksLikeMediaUuidMap($value)) {
            return self::resolveFromMediaUuids(array_values($value));
        }

        return parent::toAttachments($value, $disk);
    }

    /**
     * Discriminate Spatie's `[uuid => uuid]` from plain FileUpload state.
     *
     * The signature is unambiguous: every entry's key equals its (string) value.
     * Filament's `mapWithKeys(fn (Media $media) => [$uuid => $uuid])` guarantees
     * this; plain FileUpload's array shapes (TempFile values, indexed paths)
     * never look like this.
     *
     * @param  array<mixed, mixed>  $value
     */
    private static function looksLikeMediaUuidMap(array $value): bool
    {
        if (empty($value)) {
            return false;
        }

        foreach ($value as $key => $entry) {
            if (! is_string($key) || ! is_string($entry) || $key !== $entry || blank($entry)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $uuids
     * @return array<int, File>
     */
    private static function resolveFromMediaUuids(array $uuids): array
    {
        if (! class_exists(Media::class)) {
            return [];
        }

        /** @var class-string<Media> $mediaClass */
        $mediaClass = (string) config(
            'media-library.media_model',
            Media::class,
        );

        $records = $mediaClass::query()->whereIn('uuid', $uuids)->get();

        return $records->map(fn ($media): File => self::storedAttachmentFor(
            self::resolveAttachmentType($media->file_name, $media->mime_type),
            $media->getPathRelativeToRoot(),
            $media->disk,
        ))->all();
    }
}
