<?php

use Filament\Forms\Components\Select;
use Statikbe\FilamentSolaris\Factories\SelectFactory;

/**
 * fuzzyMatch() returns a [key, distance] tuple (or [null, null]) and uses a
 * length-relative threshold: allowed edit distance scales with the longer
 * string, and values/labels below the configured min length are skipped.
 */
function invokeFuzzy(SelectFactory $factory, string $value, array $options): array
{
    return (new ReflectionMethod($factory, 'fuzzyMatch'))->invoke($factory, $value, $options);
}

it('returns [key, distance] for a match within the relative threshold', function () {
    $factory = SelectFactory::make(Select::make('category'));

    // "Opinon" (6) → "Opinions" (8): distance 2, allowed floor(8 * 0.25) = 2
    expect(invokeFuzzy($factory, 'Opinon', ['news' => 'News', 'opinion' => 'Opinions']))
        ->toBe(['opinion', 2]);
});

it('returns [null, null] when distance exceeds the relative threshold', function () {
    $factory = SelectFactory::make(Select::make('category'));

    expect(invokeFuzzy($factory, 'completely_different', ['news' => 'News']))
        ->toBe([null, null]);
});

it('skips fuzzy matching for strings shorter than the min length', function () {
    $factory = SelectFactory::make(Select::make('category'));

    // "Cas"/"Cat" are distance 1 but only 3 chars — below the default min of 4,
    // so the semantically-risky short-string match is refused.
    expect(invokeFuzzy($factory, 'Cas', ['cat' => 'Cat', 'car' => 'Car', 'cap' => 'Cap']))
        ->toBe([null, null]);
});

it('tolerates proportionally more edits on longer strings', function () {
    $factory = SelectFactory::make(Select::make('state'));

    // "Massachusets" → "Massachusetts" is one insertion (distance 1) on a
    // 13-char label: allowed floor(13 * 0.25) = 3 → matches.
    expect(invokeFuzzy($factory, 'Massachusets', ['ma' => 'Massachusetts']))
        ->toBe(['ma', 1]);
});

it('picks the closest option when several are within threshold', function () {
    $factory = SelectFactory::make(Select::make('framework'));

    $options = ['laravel' => 'Laravel', 'lumen' => 'Laravel Lumen'];

    // "Laravel" matches the first exactly via the d=0 path through the loop
    [$key, $distance] = invokeFuzzy($factory, 'Laravvel', $options);
    expect($key)->toBe('laravel')->and($distance)->toBe(1);
});

it('returns [null, null] when fuzzy matching is disabled for the field', function () {
    $factory = SelectFactory::make(Select::make('category'))->fuzzyMatching(false);

    expect(invokeFuzzy($factory, 'Opinon', ['opinion' => 'Opinions']))
        ->toBe([null, null]);
});

it('respects a per-field threshold override', function () {
    // Tighter threshold (0.1) on an 8-char label allows floor(8 * 0.1) = 0 → max(1) = 1 edit.
    // distance 2 no longer matches.
    $factory = SelectFactory::make(Select::make('category'))->fuzzyThreshold(0.1);

    expect(invokeFuzzy($factory, 'Opinon', ['opinion' => 'Opinions']))
        ->toBe([null, null]);
});
