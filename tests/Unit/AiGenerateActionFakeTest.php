<?php

use Statikbe\FilamentSolaris\Testing\AiGenerateActionFake;
use Statikbe\FilamentSolaris\Testing\AiGenerateActionFakeException;

afterEach(fn () => AiGenerateActionFake::reset());

it('is inactive by default', function () {
    expect(AiGenerateActionFake::isActive())->toBeFalse();
});

it('activates with a canned response', function () {
    AiGenerateActionFake::activate(['records' => [['name' => 'A']]]);

    expect(AiGenerateActionFake::isActive())->toBeTrue()
        ->and(AiGenerateActionFake::getInstance()->getResponse())->toBe(['records' => [['name' => 'A']]]);
});

it('records calls and asserts on the handled data', function () {
    $fake = AiGenerateActionFake::activate(['records' => []]);
    $fake->recordCall('seed', ['records' => [['name' => 'A']]]);

    $fake->assertCalled();
    $fake->assertCalledTimes(1);
    $fake->assertHandledWith(fn (array $data) => expect($data['records'])->toHaveCount(1));
});

it('asserts not called', function () {
    AiGenerateActionFake::activate([])->assertNotCalled();
});

it('flags error simulation', function () {
    expect(AiGenerateActionFake::activateError('boom')->shouldSimulateError())->toBeTrue();
});

it('queues per-call responses with fakeEach()', function () {
    $fake = AiGenerateActionFake::fakeEach([
        ['name' => 'Alice'],
        ['name' => 'Bob'],
    ]);

    expect($fake->getResponse())->toBe(['name' => 'Alice'])
        ->and($fake->getResponse())->toBe(['name' => 'Bob']);
});

it('throws when fakeEach() queue is exhausted', function () {
    $fake = AiGenerateActionFake::fakeEach([['x' => 1]]);

    $fake->getResponse();
    $fake->getResponse();
})->throws(AiGenerateActionFakeException::class, 'fakeEach queue exhausted');
