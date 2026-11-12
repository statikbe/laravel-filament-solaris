<?php

use Statikbe\FilamentSolaris\Support\PreviewSelection;

it('seeds the keys the predicate ticks, in order', function () {
    expect(PreviewSelection::seed(['a', 'b', 'c'], fn (string $k) => $k !== 'b'))->toBe(['a', 'c'])
        ->and(PreviewSelection::seed(['a', 'b'], fn () => false))->toBe([]);
});

it('re-seeds a changed value by the predicate and keeps unchanged ticks', function () {
    $reseed = fn (array $selected, array $previousValues, array $values, bool $starts) => PreviewSelection::reseedByValue(
        $selected, ['a', 'b'], ['a', 'b'], $previousValues, $values, fn () => $starts,
    );

    // Changed value: re-seeded to the predicate, whatever the user had.
    expect($reseed(['a', 'b'], ['a' => 1, 'b' => 2], ['a' => 9, 'b' => 2], false))->toBe(['b'])
        ->and($reseed([], ['a' => 1, 'b' => 2], ['a' => 9, 'b' => 2], true))->toBe(['a'])
        // Unchanged value: the user's choice stays, both ways.
        ->and($reseed(['a'], ['a' => 1, 'b' => 2], ['a' => 1, 'b' => 2], true))->toBe(['a'])
        ->and($reseed(['b'], ['a' => 1, 'b' => 2], ['a' => 1, 'b' => 2], false))->toBe(['b']);
});

it('seeds a new key, drops a vanished key and tolerates a non-array selection', function () {
    $result = PreviewSelection::reseedByValue(
        'garbage', ['a', 'gone'], ['a', 'new'], ['a' => 1, 'gone' => 1], ['a' => 1, 'new' => 5], fn (string $k) => $k === 'new',
    );

    expect($result)->toBe(['new']);
});
