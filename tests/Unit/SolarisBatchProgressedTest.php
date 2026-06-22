<?php

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Statikbe\FilamentSolaris\Events\SolarisBatchProgressed;

it('carries per-chunk progress counts', function () {
    $e = new SolarisBatchProgressed('run-1', 'importCategories', 8, 2, 1);

    expect($e->runId)->toBe('run-1')
        ->and($e->actionName)->toBe('importCategories')
        ->and($e->succeeded)->toBe(8)
        ->and($e->failed)->toBe(2)
        ->and($e->discarded)->toBe(1);
});

it('broadcasts on the run public channel when broadcasting is forced on', function () {
    config()->set('filament-solaris.batch_tracking.live_updates.broadcast', true);
    $e = new SolarisBatchProgressed('run-1', 'importCategories', 8, 2, 1);

    expect($e)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($e->broadcastOn())->toEqual(new Channel('solaris.batch.run-1'))
        ->and($e->broadcastAs())->toBe('solaris.batch.progressed')
        ->and($e->broadcastWhen())->toBeTrue()
        ->and($e->broadcastWith())->toMatchArray(['succeeded' => 8, 'failed' => 2, 'discarded' => 1]);
});

it('does not broadcast when broadcast is auto and no driver is configured', function () {
    config()->set('filament-solaris.batch_tracking.live_updates.broadcast', null);
    config()->set('broadcasting.default', 'null');

    expect((new SolarisBatchProgressed('run-1', 'x', 1, 0, 0))->broadcastWhen())->toBeFalse();
});

it('auto-broadcasts when a real driver is configured', function () {
    config()->set('filament-solaris.batch_tracking.live_updates.broadcast', null);
    config()->set('broadcasting.default', 'reverb');

    expect((new SolarisBatchProgressed('run-1', 'x', 1, 0, 0))->broadcastWhen())->toBeTrue();
});
