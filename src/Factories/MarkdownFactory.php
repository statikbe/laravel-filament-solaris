<?php

namespace Statikbe\FilamentSolaris\Factories;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;

class MarkdownFactory extends ComponentFactory
{
    /**
     * {@inheritDoc}
     */
    public function responseSchema(JsonSchemaTypeFactory $schema): Type
    {
        $structural = 'Markdown-formatted text. Use standard Markdown syntax: headings (#, ##, ###), bold (**text**), italic (*text*), lists (- or 1.), links ([text](url)), code (`inline` or fenced blocks), blockquotes (>).';

        $component = $this->component;

        if (method_exists($component, 'getMaxLength') && $component->getMaxLength()) {
            $structural .= " Maximum {$component->getMaxLength()} characters.";
        }

        return $schema->string()
            ->description($this->buildDescription($structural))
            ->required();
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
     */
    public function toPreviewDisplay(mixed $formValue): array
    {
        if ($formValue === null || $formValue === '') {
            return ['display' => '', 'type' => 'text'];
        }

        return [
            'display' => Str::markdown((string) $formValue),
            'type' => 'html',
        ];
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
