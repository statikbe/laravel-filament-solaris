<?php

use Statikbe\FilamentSolaris\Testing\DictationToolbarActionFake;

afterEach(fn () => DictationToolbarActionFake::reset());

it('is inactive by default', function () {
    expect(DictationToolbarActionFake::isActive())->toBeFalse();
});

it('activates with a transcript', function () {
    DictationToolbarActionFake::activate('Hello world.');

    expect(DictationToolbarActionFake::isActive())->toBeTrue()
        ->and(DictationToolbarActionFake::getInstance()->getTranscript())->toBe('Hello world.');
});

it('records calls and asserts them', function () {
    $fake = DictationToolbarActionFake::activate('Text.');
    $fake->recordCall('solarisDictation', 'Text.');

    $fake->assertCalled();
    $fake->assertTranscribed();
    $fake->assertCalledTimes(1);
    $fake->assertTranscribedWith(fn (string $t) => expect($t)->toBe('Text.'));
});

it('asserts not called when nothing recorded', function () {
    DictationToolbarActionFake::activate('Text.')->assertNotCalled();
});
