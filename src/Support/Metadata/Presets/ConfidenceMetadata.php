<?php

namespace Statikbe\FilamentSolaris\Support\Metadata\Presets;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use InvalidArgumentException;
use Statikbe\FilamentSolaris\Contracts\ConfidenceBand;
use Statikbe\FilamentSolaris\Contracts\MetadataVocabulary;
use Statikbe\FilamentSolaris\Enums\ConfidenceLevel;

/**
 * Asks the model for a 0-1 confidence score per value and buckets it into the
 * levels of a ConfidenceBand enum.
 */
final class ConfidenceMetadata extends VocabularyMetadata
{
    /**
     * @param  class-string  $levels
     *
     * @throws InvalidArgumentException
     */
    public function __construct(string $levels = ConfidenceLevel::class, bool $reasoning = false)
    {
        parent::__construct($levels, $reasoning);

        if (! is_a($levels, ConfidenceBand::class, true) || ! is_a($levels, \UnitEnum::class, true) || $levels::cases() === []) {
            throw new InvalidArgumentException("[{$levels}] must be an enum implementing ".ConfidenceBand::class.' with at least one case.');
        }
    }

    public function keys(JsonSchemaTypeFactory $schema): array
    {
        return [
            'confidence' => $schema->number()->required(),
            ...$this->reasoningKeys($schema),
        ];
    }

    public function instructions(): string
    {
        $lines = ['confidence: a number from 0 to 1.'];

        foreach ($this->bandsDescending() as $band) {
            $lines[] = '- '.(string) $band->threshold().' or higher: '.$band->promptDescription();
        }

        if ($reasoning = $this->reasoningInstructions()) {
            $lines[] = $reasoning;
        }

        return implode("\n", $lines);
    }

    public function case(array $metadata): ?MetadataVocabulary
    {
        $score = $metadata['confidence'] ?? null;

        if (! is_int($score) && ! is_float($score) && ! (is_string($score) && is_numeric($score))) {
            return null;
        }

        $score = max(0.0, min(1.0, (float) $score));

        foreach ($this->bandsDescending() as $band) {
            if ($score >= $band->threshold()) {
                return $band;
            }
        }

        return null;
    }

    /**
     * @return list<ConfidenceBand&\BackedEnum>
     */
    private function bandsDescending(): array
    {
        /** @var list<ConfidenceBand&\BackedEnum> $bands */
        $bands = $this->vocabulary::cases();

        usort($bands, fn (ConfidenceBand $a, ConfidenceBand $b) => $b->threshold() <=> $a->threshold());

        return $bands;
    }
}
