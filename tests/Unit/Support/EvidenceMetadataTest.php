<?php

use Filament\Schemas\Components\Text;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Statikbe\FilamentSolaris\Contracts\MetadataVocabulary;
use Statikbe\FilamentSolaris\Enums\Evidence;
use Statikbe\FilamentSolaris\Support\Metadata\ClosureFieldMetadata;
use Statikbe\FilamentSolaris\Support\Metadata\Presets\EvidenceMetadata;

enum EvidenceTestIntQuality: int implements MetadataVocabulary
{
    case A = 1;
    case B = 2;

    public function promptDescription(): string
    {
        return 'custom '.$this->name;
    }

    public function startsSelected(): bool
    {
        return $this === self::A;
    }
}

enum EvidenceTestUnit
{
    case X;
}

enum EvidenceTestPlain: string
{
    case X = 'x';
}

it('passes make() arguments to the constructor', function () {
    $keys = EvidenceMetadata::make(reasoning: true)->keys(new JsonSchemaTypeFactory);

    expect(array_keys($keys))->toBe(['evidence', 'reasoning']);
});

it('rejects a vocabulary that is not a backed MetadataVocabulary enum', function (string $vocabulary) {
    new EvidenceMetadata($vocabulary);
})->with([stdClass::class, EvidenceTestUnit::class, EvidenceTestPlain::class])
    ->throws(InvalidArgumentException::class);

it('puts the enum cases in the evidence schema', function () {
    $s = new JsonSchemaTypeFactory;

    $evidence = EvidenceMetadata::make()->keys($s)['evidence']->toArray();
    expect($evidence['enum'])->toBe(['verbatim', 'derived']);

    $custom = EvidenceMetadata::make(EvidenceTestIntQuality::class)->keys($s)['evidence']->toArray();
    expect($custom['type'])->toBe('integer')->and($custom['enum'])->toBe([1, 2]);

    $required = (new JsonSchemaTypeFactory)->object(EvidenceMetadata::make()->keys($s))->toArray()['required'];
    expect($required)->toContain('evidence');
});

it('adds a required reasoning key only when asked', function () {
    $s = new JsonSchemaTypeFactory;

    expect(EvidenceMetadata::make()->keys($s))->not->toHaveKey('reasoning');

    $keys = EvidenceMetadata::make(reasoning: true)->keys($s);
    $required = $s->object($keys)->toArray()['required'];

    expect($keys)->toHaveKey('reasoning')->and($required)->toContain('reasoning');
});

it('builds instructions from the cases', function () {
    expect(EvidenceMetadata::make()->instructions())->toBe(<<<'TXT'
evidence: one of
- "verbatim": the value is traceable to an explicit statement in the source
- "derived": the value is inferred, summarised, reorganised or reformatted from the source
If you cannot support a value from the source, return null for the field rather than a low-evidence guess.
TXT);

    expect(EvidenceMetadata::make(reasoning: true)->instructions())
        ->toEndWith("\nreasoning: one short sentence explaining the value.");

    expect(EvidenceMetadata::make(EvidenceTestIntQuality::class)->instructions())->toContain('custom A');
});

it('resolves the case, badge and starting tick', function () {
    $preset = EvidenceMetadata::make();

    expect($preset->case(['evidence' => 'verbatim']))->toBe(Evidence::Verbatim);

    $badge = $preset->badge(['evidence' => 'verbatim']);
    expect($badge)->toBeInstanceOf(Text::class)
        ->and($badge->getContent())->toBe(Evidence::Verbatim->getLabel())
        ->and($badge->getColor())->toBe('success')
        ->and($badge->isBadge())->toBeTrue()
        ->and($preset->startsSelected(['evidence' => 'verbatim']))->toBeTrue()
        ->and($preset->startsSelected(['evidence' => 'derived']))->toBeFalse();

    foreach ([['evidence' => 'bogus'], []] as $metadata) {
        expect($preset->case($metadata))->toBeNull()
            ->and($preset->badge($metadata))->toBeNull()
            ->and($preset->startsSelected($metadata))->toBeTrue();
    }

    $int = EvidenceMetadata::make(EvidenceTestIntQuality::class);
    expect($int->case(['evidence' => 1]))->toBe(EvidenceTestIntQuality::A)
        ->and($int->case(['evidence' => '2']))->toBe(EvidenceTestIntQuality::B);
});

it('falls back to the case name and gray without HasLabel/HasColor', function () {
    $badge = EvidenceMetadata::make(EvidenceTestIntQuality::class)->badge(['evidence' => 1]);

    expect($badge->getContent())->toBe('A')->and($badge->getColor())->toBe('gray');
});

it('keeps closure metadata without badge and fully ticked', function () {
    $metadata = new ClosureFieldMetadata(fn ($s) => ['x' => $s->string()]);

    expect($metadata->badge(['x' => 'y']))->toBeNull()
        ->and($metadata->startsSelected(['x' => 'y']))->toBeTrue();
});
