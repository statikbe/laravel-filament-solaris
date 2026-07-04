<?php

use Statikbe\FilamentSolaris\Sanitizers\CallableSanitizer;
use Statikbe\FilamentSolaris\Sanitizers\Sanitizer;

it('applies the wrapped closure', function () {
    $s = new CallableSanitizer(fn (string $value): string => strtoupper($value));

    expect($s->sanitize('hi'))->toBe('HI');
});

it('is a Sanitizer', function () {
    expect(new CallableSanitizer(fn (string $v): string => $v))->toBeInstanceOf(Sanitizer::class);
});
