<?php

namespace Statikbe\FilamentSolaris\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\AiException;
use Statikbe\FilamentSolaris\Actions\SolarisAction;

/**
 * Dispatched when a Solaris AI call raises an AiException.
 *
 * Parallel to {@see SolarisResponseReceived} for monitoring + alerting.
 * Useful for catching rate limits, provider outages, and configuration
 * errors in production without tailing logs.
 *
 * Dispatched from {@see SolarisAction::executeAiCall()} before the
 * action-specific error notification is sent and before the call returns
 * null to its caller.
 */
final class SolarisResponseFailed
{
    use Dispatchable;

    /**
     * @param  class-string<SolarisAction>  $actionClass
     * @param  Lab|array<string, string>|array<int, string>|string|null  $provider
     */
    public function __construct(
        public readonly string $actionName,
        public readonly string $actionClass,
        public readonly AiException $exception,
        public readonly Lab|array|string|null $provider,
        public readonly ?string $model,
        public readonly int $durationMs,
        public readonly ?Authenticatable $user,
        public readonly ?object $livewireComponent,
    ) {}
}
