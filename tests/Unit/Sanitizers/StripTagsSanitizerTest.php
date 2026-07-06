<?php

use Statikbe\FilamentSolaris\Sanitizers\Sanitizer;
use Statikbe\FilamentSolaris\Sanitizers\StripTagsSanitizer;

it('removes html tags but keeps the text content', function () {
    expect((new StripTagsSanitizer)->sanitize('Hello <b>world</b>'))->toBe('Hello world');
});

it('strips a script tag', function () {
    expect((new StripTagsSanitizer)->sanitize('hi <script>alert(1)</script>'))->toBe('hi alert(1)');
});

it('leaves a plain string untouched', function () {
    expect((new StripTagsSanitizer)->sanitize('just text'))->toBe('just text');
});

it('is a Sanitizer', function () {
    expect(new StripTagsSanitizer)->toBeInstanceOf(Sanitizer::class);
});
