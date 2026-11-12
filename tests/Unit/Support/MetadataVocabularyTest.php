<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Statikbe\FilamentSolaris\Enums\ConfidenceLevel;
use Statikbe\FilamentSolaris\Enums\Evidence;
use Statikbe\FilamentSolaris\Support\Schema\BackedEnumType;

enum IntQuality: int
{
    case A = 1;
    case B = 2;
}

enum UnitKind
{
    case X;
}

it('ships evidence cases with their selection and colour', function () {
    expect(Evidence::Verbatim->startsSelected())->toBeTrue()
        ->and(Evidence::Derived->startsSelected())->toBeFalse()
        ->and(Evidence::Verbatim->getColor())->toBe('success')
        ->and(Evidence::Derived->getColor())->toBe('warning')
        ->and(Evidence::Verbatim->getLabel())->toBe(filament_solaris_trans('metadata.evidence.verbatim'))
        ->and(Evidence::Verbatim->getLabel())->not->toContain('metadata.')
        ->and(Evidence::Verbatim->promptDescription())->toBe('the value is traceable to an explicit statement in the source');
});

it('ships confidence levels with thresholds', function () {
    expect(ConfidenceLevel::High->threshold())->toBe(0.8)
        ->and(ConfidenceLevel::Medium->threshold())->toBe(0.5)
        ->and(ConfidenceLevel::Low->threshold())->toBe(0.0)
        ->and(ConfidenceLevel::High->startsSelected())->toBeTrue()
        ->and(ConfidenceLevel::Medium->startsSelected())->toBeFalse()
        ->and(ConfidenceLevel::Low->startsSelected())->toBeFalse()
        ->and(ConfidenceLevel::High->getColor())->toBe('success')
        ->and(ConfidenceLevel::Medium->getColor())->toBe('warning')
        ->and(ConfidenceLevel::Low->getColor())->toBe('danger')
        ->and(ConfidenceLevel::Low->getLabel())->toBe(filament_solaris_trans('metadata.confidence.low'));
});

it('builds a string or integer enum type from a backed enum', function () {
    $schema = new JsonSchemaTypeFactory;

    $string = BackedEnumType::for($schema, Evidence::class)->toArray();
    expect($string['type'])->toBe('string')->and($string['enum'])->toBe(['verbatim', 'derived']);

    $int = BackedEnumType::for($schema, IntQuality::class)->toArray();
    expect($int['type'])->toBe('integer')->and($int['enum'])->toBe([1, 2]);

    expect(BackedEnumType::for($schema, UnitKind::class))->toBeNull()
        ->and(BackedEnumType::for($schema, stdClass::class))->toBeNull();
});
