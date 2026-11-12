<?php

namespace Statikbe\FilamentSolaris\Support\Metadata\Presets;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Statikbe\FilamentSolaris\Contracts\MetadataVocabulary;
use Statikbe\FilamentSolaris\Enums\Evidence;
use Statikbe\FilamentSolaris\Support\Schema\BackedEnumType;

/**
 * Asks the model how each value relates to the source: stated or inferred.
 */
final class EvidenceMetadata extends VocabularyMetadata
{
    /**
     * @param  class-string  $vocabulary
     */
    public function __construct(string $vocabulary = Evidence::class, bool $reasoning = false)
    {
        parent::__construct($vocabulary, $reasoning);
    }

    public function keys(JsonSchemaTypeFactory $schema): array
    {
        return [
            'evidence' => BackedEnumType::for($schema, $this->vocabulary)->required(),
            ...$this->reasoningKeys($schema),
        ];
    }

    public function instructions(): string
    {
        $lines = ['evidence: one of'];

        foreach ($this->vocabulary::cases() as $case) {
            $lines[] = '- "'.$case->value.'": '.$case->promptDescription();
        }

        $lines[] = 'If you cannot support a value from the source, return null for the field rather than a low-evidence guess.';

        if ($reasoning = $this->reasoningInstructions()) {
            $lines[] = $reasoning;
        }

        return implode("\n", $lines);
    }

    public function case(array $metadata): ?MetadataVocabulary
    {
        return $this->resolveCase($metadata['evidence'] ?? null);
    }
}
