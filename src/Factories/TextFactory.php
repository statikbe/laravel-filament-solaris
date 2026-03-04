<?php

namespace Statikbe\FilamentSolaris\Factories;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

class TextFactory extends ComponentFactory
{
    /**
     * {@inheritDoc}
     */
    public function responseSchema(JsonSchema $schema): Type
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
     */
    public function toPromptContext(mixed $formValue): mixed
    {
        if ($formValue === null) {
            return '';
        }

        return (string) $formValue;
    }
}
