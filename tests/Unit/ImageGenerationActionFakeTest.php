<?php

use PHPUnit\Framework\ExpectationFailedException;
use Statikbe\FilamentSolaris\Testing\ImageGenerationActionFake;

beforeEach(function () {
    ImageGenerationActionFake::reset();
});

afterEach(function () {
    ImageGenerationActionFake::reset();
});

it('is inactive by default', function () {
    expect(ImageGenerationActionFake::isActive())->toBeFalse();
});

it('activates with default stored path', function () {
    ImageGenerationActionFake::activate();

    expect(ImageGenerationActionFake::isActive())->toBeTrue()
        ->and(ImageGenerationActionFake::getInstance()->getStoredPath())->toBe('ai-images/fake-image.png');
});

it('activates with custom stored path', function () {
    ImageGenerationActionFake::activate('custom/path/image.jpg');

    expect(ImageGenerationActionFake::getInstance()->getStoredPath())->toBe('custom/path/image.jpg');
});

it('resets properly', function () {
    ImageGenerationActionFake::activate();
    ImageGenerationActionFake::reset();

    expect(ImageGenerationActionFake::isActive())->toBeFalse();
});

it('records calls', function () {
    $fake = ImageGenerationActionFake::activate();

    $fake->recordCall('generate', 'A sunset photo');
    $fake->recordCall('generate', 'A mountain photo');

    $fake->assertCalledTimes(2);
});

it('asserts called', function () {
    $fake = ImageGenerationActionFake::activate();

    $fake->recordCall('generate', 'A sunset photo');

    $fake->assertCalled();
});

it('asserts called fails when not called', function () {
    $fake = ImageGenerationActionFake::activate();

    expect(fn () => $fake->assertCalled())->toThrow(
        ExpectationFailedException::class,
    );
});

it('asserts not called', function () {
    $fake = ImageGenerationActionFake::activate();

    $fake->assertNotCalled();
});

it('asserts not called fails when called', function () {
    $fake = ImageGenerationActionFake::activate();
    $fake->recordCall('generate', 'A sunset photo');

    expect(fn () => $fake->assertNotCalled())->toThrow(
        ExpectationFailedException::class,
    );
});

it('asserts called with callback', function () {
    $fake = ImageGenerationActionFake::activate();

    $fake->recordCall('generate', 'A sunset photo', '1:1', 'high', 'openai', 'gpt-image-1.5');

    $fake->assertCalledWith(function (string $prompt, ?string $size, ?string $quality, $provider, ?string $model) {
        expect($prompt)->toBe('A sunset photo')
            ->and($size)->toBe('1:1')
            ->and($quality)->toBe('high')
            ->and($provider)->toBe('openai')
            ->and($model)->toBe('gpt-image-1.5');
    });
});

it('asserts called times', function () {
    $fake = ImageGenerationActionFake::activate();

    $fake->recordCall('generate', 'Photo 1');
    $fake->recordCall('generate', 'Photo 2');
    $fake->recordCall('generate', 'Photo 3');

    $fake->assertCalledTimes(3);
});

it('asserts called times fails with wrong count', function () {
    $fake = ImageGenerationActionFake::activate();

    $fake->recordCall('generate', 'Photo 1');

    expect(fn () => $fake->assertCalledTimes(2))->toThrow(
        ExpectationFailedException::class,
    );
});
