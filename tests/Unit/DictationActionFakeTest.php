<?php

use PHPUnit\Framework\ExpectationFailedException;
use Statikbe\FilamentSolaris\Testing\AiActionFake;
use Statikbe\FilamentSolaris\Testing\DictationActionFake;

beforeEach(function () {
    DictationActionFake::reset();
    AiActionFake::reset();
});

afterEach(function () {
    DictationActionFake::reset();
    AiActionFake::reset();
});

it('is inactive by default', function () {
    expect(DictationActionFake::isActive())->toBeFalse();
});

it('activates with a transcript', function () {
    DictationActionFake::activate('Hello world');

    expect(DictationActionFake::isActive())->toBeTrue()
        ->and(DictationActionFake::getInstance()->getTranscript())->toBe('Hello world');
});

it('activates AiActionFake when AI response is provided', function () {
    DictationActionFake::activate('Hello', ['summary' => 'Test']);

    expect(DictationActionFake::isActive())->toBeTrue()
        ->and(AiActionFake::isActive())->toBeTrue();
});

it('does not activate AiActionFake when no AI response is provided', function () {
    DictationActionFake::activate('Hello');

    expect(DictationActionFake::isActive())->toBeTrue()
        ->and(AiActionFake::isActive())->toBeFalse();
});

it('resets both fakes when AI response was provided', function () {
    DictationActionFake::activate('Hello', ['summary' => 'Test']);
    DictationActionFake::reset();

    expect(DictationActionFake::isActive())->toBeFalse()
        ->and(AiActionFake::isActive())->toBeFalse();
});

it('records calls', function () {
    $fake = DictationActionFake::activate('Hello');

    $fake->recordCall('dictate', 'Hello');
    $fake->recordCall('dictate', 'World');

    $fake->assertCalledTimes(2);
});

it('asserts called', function () {
    $fake = DictationActionFake::activate('Hello');

    $fake->recordCall('dictate', 'Hello');

    $fake->assertCalled();
});

it('asserts not called', function () {
    $fake = DictationActionFake::activate('Hello');

    $fake->assertNotCalled();
});

it('asserts not called fails when called', function () {
    $fake = DictationActionFake::activate('Hello');
    $fake->recordCall('dictate', 'Hello');

    expect(fn () => $fake->assertNotCalled())->toThrow(
        ExpectationFailedException::class,
    );
});

it('asserts transcribed', function () {
    $fake = DictationActionFake::activate('Hello world');

    $fake->recordCall('dictate', 'Hello world');

    $fake->assertTranscribed();
});

it('asserts transcribed fails for empty transcript', function () {
    $fake = DictationActionFake::activate('');

    $fake->recordCall('dictate', '');

    expect(fn () => $fake->assertTranscribed())->toThrow(
        ExpectationFailedException::class,
    );
});

it('asserts transcribed with callback', function () {
    $fake = DictationActionFake::activate('Hello world');

    $fake->recordCall('dictate', 'Hello world');

    $fake->assertTranscribedWith(function (string $transcript) {
        expect($transcript)->toBe('Hello world');
    });
});

it('asserts called times', function () {
    $fake = DictationActionFake::activate('Hello');

    $fake->recordCall('dictate', 'Hello');
    $fake->recordCall('dictate', 'Hello');
    $fake->recordCall('dictate', 'Hello');

    $fake->assertCalledTimes(3);
});

it('asserts called times fails with wrong count', function () {
    $fake = DictationActionFake::activate('Hello');

    $fake->recordCall('dictate', 'Hello');

    expect(fn () => $fake->assertCalledTimes(2))->toThrow(
        ExpectationFailedException::class,
    );
});
