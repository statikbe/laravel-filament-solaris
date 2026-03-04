<?php

namespace Statikbe\FilamentSolaris\Factories;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;

class BooleanFactory extends ComponentFactory
{
    /**
     * {@inheritDoc}
     */
    public function responseSchema(JsonSchemaTypeFactory $schema): Type
    {
        return $schema->boolean()
            ->description($this->buildDescription('true or false'))
            ->required();
    }

    /**
     * {@inheritDoc}
     */
    public function toFormValue(mixed $aiValue): mixed
    {
        if (is_bool($aiValue)) {
            return $aiValue;
        }

        if (is_string($aiValue)) {
            $lower = mb_strtolower(trim($aiValue));

            if (in_array($lower, ['true', 'yes', '1'], true)) {
                return true;
            }

            if (in_array($lower, ['false', 'no', '0'], true)) {
                return false;
            }
        }

        if (is_int($aiValue)) {
            return $aiValue === 1;
        }

        Log::warning("FilamentSolaris: Could not interpret '{$aiValue}' as boolean, defaulting to false.");

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function toPromptContext(mixed $formValue): mixed
    {
        if ($formValue === null) {
            return 'Not set';
        }

        return $formValue ? 'Yes' : 'No';
    }
}
