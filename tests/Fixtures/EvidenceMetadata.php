<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Statikbe\FilamentSolaris\Support\Metadata\FieldMetadata;

class EvidenceMetadata extends FieldMetadata
{
    public function keys(JsonSchemaTypeFactory $schema): array
    {
        return ['evidence' => $schema->string()->enum(['verbatim', 'derived'])->required()];
    }

    public function instructions(): ?string
    {
        return 'Preset evidence instructions.';
    }
}
