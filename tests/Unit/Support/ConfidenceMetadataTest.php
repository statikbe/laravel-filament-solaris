<?php

use Filament\Schemas\Components\Text;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Statikbe\FilamentSolaris\Contracts\ConfidenceBand;
use Statikbe\FilamentSolaris\Enums\ConfidenceLevel;
use Statikbe\FilamentSolaris\Support\Metadata\Presets\ConfidenceMetadata;

enum ConfidenceTestBand: string implements ConfidenceBand
{
    case Sure = 'sure';
    case Unsure = 'unsure';

    public function threshold(): float
    {
        return match ($this) {
            self::Sure => 0.7,
            self::Unsure => 0.3,
        };
    }

    public function promptDescription(): string
    {
        return 'custom '.$this->name;
    }

    public function startsSelected(): bool
    {
        return $this === self::Sure;
    }
}

enum ConfidenceTestNotBand: string
{
    case X = 'x';
}

enum ConfidenceTestNoCases implements ConfidenceBand
{
    public function threshold(): float
    {
        return 0.0;
    }

    public function promptDescription(): string
    {
        return '';
    }

    public function startsSelected(): bool
    {
        return true;
    }
}

it('rejects levels that are not a ConfidenceBand enum with cases', function () {
    expect(fn () => new ConfidenceMetadata('NopeClass'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ConfidenceMetadata(ConfidenceTestNotBand::class))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ConfidenceMetadata(ConfidenceTestNoCases::class))->toThrow(InvalidArgumentException::class);
});

it('asks for a required confidence number', function () {
    $keys = ConfidenceMetadata::make()->keys(new JsonSchemaTypeFactory);
    $schema = $keys['confidence']->toArray();

    expect(array_keys($keys))->toBe(['confidence'])
        ->and($schema['type'])->toBe('number')
        ->and($schema)->not->toHaveKeys(['enum', 'minimum', 'maximum']);

    $object = (new JsonSchemaTypeFactory)->object($keys)->toArray();
    expect($object['required'])->toContain('confidence');
});

it('adds a required reasoning key when asked', function () {
    $keys = ConfidenceMetadata::make(reasoning: true)->keys(new JsonSchemaTypeFactory);

    expect(array_keys($keys))->toBe(['confidence', 'reasoning']);
});

it('buckets scores by the highest reached threshold', function (mixed $score, ?ConfidenceLevel $expected) {
    expect(ConfidenceMetadata::make()->case(['confidence' => $score]))->toBe($expected);
})->with([
    [0.8, ConfidenceLevel::High],
    [0.79, ConfidenceLevel::Medium],
    [0.5, ConfidenceLevel::Medium],
    [0.49, ConfidenceLevel::Low],
    [0, ConfidenceLevel::Low],
    [1.3, ConfidenceLevel::High],
    [-0.2, ConfidenceLevel::Low],
    ['0.9', ConfidenceLevel::High],
    ['high', null],
    [null, null],
    [[], null],
]);

it('returns null for a missing key and for scores below the lowest custom threshold', function () {
    $preset = ConfidenceMetadata::make(ConfidenceTestBand::class);

    expect($preset->case([]))->toBeNull()
        ->and($preset->case(['confidence' => 0.1]))->toBeNull()
        ->and($preset->case(['confidence' => 0.3]))->toBe(ConfidenceTestBand::Unsure)
        ->and($preset->case(['confidence' => 0.7]))->toBe(ConfidenceTestBand::Sure);
});

it('builds instructions highest threshold first', function () {
    expect(ConfidenceMetadata::make()->instructions())->toBe(<<<'TXT'
confidence: a number from 0 to 1.
- 0.8 or higher: the source states it explicitly
- 0.5 or higher: the source strongly implies it
- 0 or higher: a guess with little support in the source
TXT);

    expect(ConfidenceMetadata::make(reasoning: true)->instructions())
        ->toEndWith("\nreasoning: one short sentence explaining the value.");
});

it('badges and seeds from the bucketed level', function () {
    $preset = ConfidenceMetadata::make();

    $high = $preset->badge(['confidence' => 0.9]);
    expect($high)->toBeInstanceOf(Text::class)
        ->and($high->getColor())->toBe('success')
        ->and($preset->startsSelected(['confidence' => 0.9]))->toBeTrue();

    $mid = $preset->badge(['confidence' => 0.6]);
    expect($mid->getColor())->toBe('warning')
        ->and($preset->startsSelected(['confidence' => 0.6]))->toBeFalse();

    expect($preset->badge([]))->toBeNull()
        ->and($preset->startsSelected([]))->toBeTrue();
});
