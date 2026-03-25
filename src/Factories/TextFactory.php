<?php

namespace Statikbe\FilamentSolaris\Factories;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;

class TextFactory extends ComponentFactory
{
    /**
     * {@inheritDoc}
     */
    public function responseSchema(JsonSchemaTypeFactory $schema): Type
    {
        $component = $this->component;
        $structural = '';

        if (method_exists($component, 'getMaxLength') && $component->getMaxLength()) {
            $structural = "Maximum {$component->getMaxLength()} characters.";
        }

        $type = $schema->string()->required();

        if (method_exists($component, 'getMaxLength') && $component->getMaxLength()) {
            $type = $type->max($component->getMaxLength());
        }

        $description = $this->buildDescription($structural);

        if ($description !== '') {
            $type = $type->description($description);
        }

        return $type;
    }

    /**
     * {@inheritDoc}
     */
    public function toFormValue(mixed $aiValue): mixed
    {
        if ($aiValue === null) {
            return null;
        }

        if (is_array($aiValue) || is_object($aiValue)) {
            return json_encode($aiValue);
        }

        return (string) $aiValue;
    }

    /**
     * {@inheritDoc}
     *
     * Stores the file to disk and returns the path string.
     */
    public function toFormValueFromFile(string $content, string $mimeType): mixed
    {
        $config = FilamentSolaris::config();
        $directory = $config->getDefaultImageDirectory();
        $disk = $config->getDefaultImageDisk();
        $visibility = $config->getDefaultImageVisibility();

        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'png',
        };

        $filename = Str::random(40).'.'.$extension;
        $path = $directory.'/'.$filename;

        $options = $visibility === 'public' ? 'public' : [];

        Storage::disk($disk)->put($path, $content, $options);

        return $path;
    }

    /**
     * {@inheritDoc}
     */
    public function toPromptContext(mixed $formValue): mixed
    {
        if ($formValue === null) {
            return '';
        }

        return (string) $formValue;
    }
}
