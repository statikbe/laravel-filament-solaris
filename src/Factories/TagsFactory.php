<?php

namespace Statikbe\FilamentSolaris\Factories;

use Filament\Forms\Components\TagsInput;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;

class TagsFactory extends ComponentFactory
{
    /**
     * {@inheritDoc}
     */
    public function responseSchema(JsonSchemaTypeFactory $schema): Type
    {
        $structural = 'An array of short text tags.';

        $suggestions = $this->resolveSuggestions();

        if (! empty($suggestions)) {
            $sample = implode(', ', array_slice($suggestions, 0, 15));
            $structural .= " Suggested values: {$sample}.";
        }

        $description = $this->buildDescription($structural);

        return $schema->array()
            ->items($schema->string())
            ->description($description)
            ->required();
    }

    /**
     * {@inheritDoc}
     */
    public function toFormValue(mixed $aiValue): mixed
    {
        if ($aiValue === null) {
            return [];
        }

        if (is_string($aiValue)) {
            $separator = $this->resolveSeparator();

            return array_map('trim', explode($separator, $aiValue));
        }

        if (is_array($aiValue)) {
            return array_values(array_map('strval', $aiValue));
        }

        return [(string) $aiValue];
    }

    /**
     * {@inheritDoc}
     */
    public function toPromptContext(mixed $formValue): mixed
    {
        if ($formValue === null || $formValue === []) {
            return '';
        }

        if (is_array($formValue)) {
            return implode(', ', $formValue);
        }

        return (string) $formValue;
    }

    /**
     * Resolve the suggestions from the component.
     *
     * @return array<int, string>
     */
    protected function resolveSuggestions(): array
    {
        if (method_exists($this->component, 'getSuggestions')) {
            return $this->component->getSuggestions();
        }

        return [];
    }

    /**
     * Resolve the separator from the component.
     */
    protected function resolveSeparator(): string
    {
        /** @var TagsInput $component */
        $component = $this->component;

        $separator = $component->getSeparator();

        return $separator ?? ',';
    }
}
