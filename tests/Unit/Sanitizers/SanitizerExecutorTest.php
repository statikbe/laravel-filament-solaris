<?php

use Statikbe\FilamentSolaris\Sanitizers\CompositeSanitizer;
use Statikbe\FilamentSolaris\Sanitizers\SanitizerExecutor;
use Statikbe\FilamentSolaris\Sanitizers\StripTagsSanitizer;
use Statikbe\FilamentSolaris\Sanitizers\TrimSanitizer;

it('applies the default sanitizer to every string value', function () {
    $executor = SanitizerExecutor::make(new StripTagsSanitizer);

    expect($executor->execute(['name' => 'a <b>b</b>', 'slug' => '<i>x</i>']))
        ->toBe(['name' => 'a b', 'slug' => 'x']);
});

it('routes a per-field override, default elsewhere', function () {
    $executor = SanitizerExecutor::make(
        default: new StripTagsSanitizer,
        fields: ['body' => new TrimSanitizer],
    );

    expect($executor->execute(['title' => '<b>T</b>', 'body' => '  <b>keep</b> ']))
        ->toBe(['title' => 'T', 'body' => '<b>keep</b>']);   // body trimmed only, not stripped
});

it('skips non-string values (single call-site guard)', function () {
    $executor = SanitizerExecutor::make(new StripTagsSanitizer);

    expect($executor->execute(['name' => '<b>x</b>', 'weight' => 5, 'active' => true, 'meta' => null]))
        ->toBe(['name' => 'x', 'weight' => 5, 'active' => true, 'meta' => null]);
});

it('leaves everything untouched when empty', function () {
    $executor = SanitizerExecutor::make(null);

    expect($executor->execute(['name' => '<b>x</b>']))->toBe(['name' => '<b>x</b>'])
        ->and($executor->isEmpty())->toBeTrue();
});

it('wraps an array into a CompositeSanitizer pipeline', function () {
    $executor = SanitizerExecutor::make([new TrimSanitizer, new StripTagsSanitizer]);

    expect($executor->execute(['x' => '  <b>hi</b> ']))->toBe(['x' => 'hi']);
});

it('wraps a closure into a CallableSanitizer', function () {
    $executor = SanitizerExecutor::make(fn (string $v): string => strtoupper($v));

    expect($executor->execute(['x' => 'hi']))->toBe(['x' => 'HI']);
});

it('is serializable when every sanitizer is a class', function () {
    $executor = SanitizerExecutor::make(
        default: new StripTagsSanitizer,
        fields: ['body' => new CompositeSanitizer([new TrimSanitizer, new StripTagsSanitizer])],
    );

    expect($executor->isSerializable())->toBeTrue();
});

it('is not serializable when a closure sanitizer is present', function () {
    $executor = SanitizerExecutor::make(fn (string $v): string => $v);

    expect($executor->isSerializable())->toBeFalse();
});

it('detects a closure nested inside a composite as non-serializable', function () {
    $executor = SanitizerExecutor::make([new TrimSanitizer, fn (string $v): string => $v]);

    expect($executor->isSerializable())->toBeFalse();
});
