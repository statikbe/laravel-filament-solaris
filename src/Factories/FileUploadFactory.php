<?php

namespace Statikbe\FilamentSolaris\Factories;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class FileUploadFactory extends ComponentFactory
{
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

        $originalName = 'ai-generated-'.Str::random(8).'.'.$extension;
        $encodedName = base64_encode($originalName);
        $filename = 'ai-'.Str::random(20).'-meta'.$encodedName.'.'.$extension;

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
}
