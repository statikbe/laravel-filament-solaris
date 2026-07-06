<?php

use Mews\Purifier\Facades\Purifier;
use Statikbe\FilamentSolaris\Sanitizers\HtmlPurifierSanitizer;
use Statikbe\FilamentSolaris\Sanitizers\Sanitizer;

it('delegates to the provided purify callback', function () {
    $s = new HtmlPurifierSanitizer(fn (string $v): string => 'clean:'.$v);

    expect($s->sanitize('<b>x</b>'))->toBe('clean:<b>x</b>');
});

it('is a Sanitizer', function () {
    expect(new HtmlPurifierSanitizer(fn (string $v): string => $v))->toBeInstanceOf(Sanitizer::class);
});

it('throws a clear install error when no purifier lib is available', function () {
    expect(fn () => (new HtmlPurifierSanitizer)->sanitize('<b>x</b>'))
        ->toThrow(RuntimeException::class, 'mews/purifier');
})->skip(
    class_exists(Purifier::class),
    'a purifier library is installed, so the default resolves instead of throwing',
);
