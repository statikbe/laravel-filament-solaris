<?php

use Statikbe\FilamentSolaris\Support\PreviewSelection;

it('keeps only configured keys that are present, in item order', function () {
    expect(PreviewSelection::selectable(['summary', 'category', 'tags'], ['tags', 'summary', 'missing']))
        ->toBe(['summary', 'tags']);
});

it('keeps unticked keys unticked across a refinement', function () {
    expect(PreviewSelection::reseed(['category'], ['summary', 'category'], ['summary', 'category']))
        ->toBe(['category']);
});

it('ticks keys that are newly selectable after a refinement', function () {
    expect(PreviewSelection::reseed([], ['summary'], ['summary', 'category']))
        ->toBe(['category']);
});

it('drops keys that are no longer selectable after a refinement', function () {
    expect(PreviewSelection::reseed(['summary', 'category'], ['summary', 'category'], ['summary']))
        ->toBe(['summary']);
});

it('writes fixed items plus ticked selectable items', function () {
    $values = ['summary' => 'S', 'category' => 'tech', 'is_featured' => true];

    expect(PreviewSelection::filter($values, ['summary', 'category'], ['category']))
        ->toBe(['category' => 'tech', 'is_featured' => true]);
});

it('ignores ticks for keys that are not selectable', function () {
    $values = ['summary' => 'S', 'category' => 'tech'];

    expect(PreviewSelection::filter($values, ['summary', 'category'], ['nonexistent']))
        ->toBe([]);
});

it('treats a non-array selection as nothing ticked', function () {
    $values = ['summary' => 'S', 'is_featured' => true];

    expect(PreviewSelection::filter($values, ['summary'], 'summary'))
        ->toBe(['is_featured' => true])
        ->and(PreviewSelection::reseed(null, ['summary'], ['summary']))
        ->toBe([]);
});

it('requires a selection only when every item is selectable', function () {
    expect(PreviewSelection::requiresSelection(['summary', 'category'], ['summary', 'category']))->toBeTrue()
        ->and(PreviewSelection::requiresSelection(['summary', 'is_featured'], ['summary']))->toBeFalse()
        ->and(PreviewSelection::requiresSelection(['is_featured'], []))->toBeFalse();
});
