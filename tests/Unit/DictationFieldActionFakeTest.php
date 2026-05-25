<?php

use PHPUnit\Framework\ExpectationFailedException;
use Statikbe\FilamentSolaris\Testing\AiFormActionFake;
use Statikbe\FilamentSolaris\Testing\DictationFieldActionFake;

beforeEach(function () {
    DictationFieldActionFake::reset();
    AiFormActionFake::reset();
});

afterEach(function () {
    DictationFieldActionFake::reset();
    AiFormActionFake::reset();
});

it('is inactive by default', function () {
    expect(DictationFieldActionFake::isActive())->toBeFalse();
});

it('activates with a transcript', function () {
    DictationFieldActionFake::activate('Hello world');

    expect(DictationFieldActionFake::isActive())->toBeTrue()
        ->and(DictationFieldActionFake::getInstance()->getTranscript())->toBe('Hello world');
});

it('activates AiFormActionFake when AI response is provided', function () {
    DictationFieldActionFake::activate('Hello', ['summary' => 'Test']);

    expect(DictationFieldActionFake::isActive())->toBeTrue()
        ->and(AiFormActionFake::isActive())->toBeTrue();
});

it('does not activate AiFormActionFake when no AI response is provided', function () {
    DictationFieldActionFake::activate('Hello');

    expect(DictationFieldActionFake::isActive())->toBeTrue()
        ->and(AiFormActionFake::isActive())->toBeFalse();
});

it('resets both fakes when AI response was provided', function () {
    DictationFieldActionFake::activate('Hello', ['summary' => 'Test']);
    DictationFieldActionFake::reset();

    expect(DictationFieldActionFake::isActive())->toBeFalse()
        ->and(AiFormActionFake::isActive())->toBeFalse();
});

it('records calls', function () {
    $fake = DictationFieldActionFake::activate('Hello');

    $fake->recordCall('dictate', 'Hello');
    $fake->recordCall('dictate', 'World');

    $fake->assertCalledTimes(2);
});

it('asserts called', function () {
    $fake = DictationFieldActionFake::activate('Hello');

    $fake->recordCall('dictate', 'Hello');

    $fake->assertCalled();
});

it('asserts not called', function () {
    $fake = DictationFieldActionFake::activate('Hello');

    $fake->assertNotCalled();
});

it('asserts not called fails when called', function () {
    $fake = DictationFieldActionFake::activate('Hello');
    $fake->recordCall('dictate', 'Hello');

    expect(fn () => $fake->assertNotCalled())->toThrow(
        ExpectationFailedException::class,
    );
});

it('asserts transcribed', function () {
    $fake = DictationFieldActionFake::activate('Hello world');

    $fake->recordCall('dictate', 'Hello world');

    $fake->assertTranscribed();
});

it('asserts transcribed fails for empty transcript', function () {
    $fake = DictationFieldActionFake::activate('');

    $fake->recordCall('dictate', '');

    expect(fn () => $fake->assertTranscribed())->toThrow(
        ExpectationFailedException::class,
    );
});

it('asserts transcribed with callback', function () {
    $fake = DictationFieldActionFake::activate('Hello world');

    $fake->recordCall('dictate', 'Hello world');

    $fake->assertTranscribedWith(function (string $transcript) {
        expect($transcript)->toBe('Hello world');
    });
});

it('asserts called times', function () {
    $fake = DictationFieldActionFake::activate('Hello');

    $fake->recordCall('dictate', 'Hello');
    $fake->recordCall('dictate', 'Hello');
    $fake->recordCall('dictate', 'Hello');

    $fake->assertCalledTimes(3);
});

it('asserts called times fails with wrong count', function () {
    $fake = DictationFieldActionFake::activate('Hello');

    $fake->recordCall('dictate', 'Hello');

    expect(fn () => $fake->assertCalledTimes(2))->toThrow(
        ExpectationFailedException::class,
    );
});
