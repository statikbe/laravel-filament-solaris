<?php

use Statikbe\FilamentSolaris\Sanitizers\CompositeSanitizer;
use Statikbe\FilamentSolaris\Sanitizers\Sanitizer;
use Statikbe\FilamentSolaris\Sanitizers\StripTagsSanitizer;
use Statikbe\FilamentSolaris\Sanitizers\TrimSanitizer;

it('runs the sanitizers in order', function () {
    // trim → strip_tags: "  <b>hi</b> " → "<b>hi</b>" → "hi"
    $composite = new CompositeSanitizer([new TrimSanitizer, new StripTagsSanitizer]);

    expect($composite->sanitize('  <b>hi</b> '))->toBe('hi');
});

it('feeds each output into the next', function () {
    $upper = new class implements Sanitizer
    {
        public function sanitize(string $value): string
        {
            return strtoupper($value);
        }
    };

    expect((new CompositeSanitizer([new TrimSanitizer, $upper]))->sanitize('  hi '))->toBe('HI');
});

it('is the identity for an empty list', function () {
    expect((new CompositeSanitizer([]))->sanitize('x <b>y</b>'))->toBe('x <b>y</b>');
});

it('is itself a Sanitizer', function () {
    expect(new CompositeSanitizer([]))->toBeInstanceOf(Sanitizer::class);
});
