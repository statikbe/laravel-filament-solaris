<?php

namespace Statikbe\FilamentSolaris\Support\Metadata\Presets;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use ReflectionEnum;
use Statikbe\FilamentSolaris\Contracts\MetadataVocabulary;
use Statikbe\FilamentSolaris\Support\Metadata\FieldMetadata;

/**
 * Shared base of the evidence/confidence presets: a metadata key whose values
 * are the cases of a backed enum implementing MetadataVocabulary. Subclasses
 * add their keys(), instructions() and the case() lookup.
 */
abstract class VocabularyMetadata extends FieldMetadata
{
    /**
     * @param  class-string  $vocabulary
     *
     * @throws InvalidArgumentException
     */
    public function __construct(protected string $vocabulary, protected bool $reasoning = false)
    {
        if (! enum_exists($vocabulary)
            || ! (new ReflectionEnum($vocabulary))->isBacked()
            || ! is_a($vocabulary, MetadataVocabulary::class, true)) {
            throw new InvalidArgumentException("[{$vocabulary}] must be a backed enum implementing ".MetadataVocabulary::class.'.');
        }
    }

    /**
     * The vocabulary case the model chose, or null when absent or unknown.
     *
     * @param  array<string, mixed>  $metadata
     * @return (MetadataVocabulary&\BackedEnum)|null
     */
    abstract public function case(array $metadata): ?MetadataVocabulary;

    public function badge(array $metadata): ?Component
    {
        $case = $this->case($metadata);

        if ($case === null) {
            return null;
        }

        return Text::make($case instanceof HasLabel ? $case->getLabel() : $case->name)
            ->badge()
            ->color($case instanceof HasColor ? $case->getColor() : 'gray');
    }

    public function startsSelected(array $metadata): bool
    {
        return $this->case($metadata)?->startsSelected() ?? true;
    }

    /**
     * Look a raw value up in the vocabulary, tolerating numeric strings for int-backed enums.
     *
     * @return (MetadataVocabulary&\BackedEnum)|null
     */
    protected function resolveCase(mixed $value): ?MetadataVocabulary
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $isInt = (new ReflectionEnum($this->vocabulary))->getBackingType()?->getName() === 'int';

        if ($isInt) {
            if (! is_numeric($value) || (int) $value != $value) {
                return null;
            }

            $value = (int) $value;
        } else {
            $value = (string) $value;
        }

        /** @var (MetadataVocabulary&\BackedEnum)|null */
        return $this->vocabulary::tryFrom($value);
    }

    /**
     * @return array<string, Type>
     */
    protected function reasoningKeys(JsonSchemaTypeFactory $s): array
    {
        return $this->reasoning ? ['reasoning' => $s->string()->required()] : [];
    }

    protected function reasoningInstructions(): ?string
    {
        return $this->reasoning ? 'reasoning: one short sentence explaining the value.' : null;
    }
}
