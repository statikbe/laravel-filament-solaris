<?php

use Statikbe\FilamentSolaris\Sanitizers\Sanitizer;
use Statikbe\FilamentSolaris\Sanitizers\TrimSanitizer;

it('trims leading and trailing whitespace', function () {
    expect((new TrimSanitizer)->sanitize("  hello \n"))->toBe('hello');
});

it('leaves inner whitespace untouched', function () {
    expect((new TrimSanitizer)->sanitize('a  b'))->toBe('a  b');
});

it('is a Sanitizer', function () {
    expect(new TrimSanitizer)->toBeInstanceOf(Sanitizer::class);
});
