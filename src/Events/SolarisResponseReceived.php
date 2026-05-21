<?php

namespace Statikbe\FilamentSolaris\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\Data\Usage;
use Statikbe\FilamentSolaris\Actions\SolarisAction;

/**
 * Dispatched after a Solaris AI call returns successfully.
 *
 * Carries the Usage object from laravel/ai plus the Solaris-specific
 * context (action name, action class, livewire component, authenticated
 * user) so apps can persist usage records, build per-user/per-action
 * dashboards, enforce budgets, etc.
 *
 * The event deliberately does NOT carry the prompt, response, or source
 * field values. Those are large, may contain PII, and would balloon
 * listener storage. Consumers that need the prompt can use
 * `SolarisPromptLogger`'s log output instead.
 */
final class SolarisResponseReceived
{
    use Dispatchable;

    /**
     * @param  class-string<SolarisAction>  $actionClass
     * @param  Lab|array<string, string>|array<int, string>|string|null  $provider
     */
    public function __construct(
        public readonly string $actionName,
        public readonly string $actionClass,
        public readonly Usage $usage,
        public readonly Lab|array|string|null $provider,
        public readonly ?string $model,
        public readonly int $durationMs,
        public readonly ?Authenticatable $user,
        public readonly ?object $livewireComponent,
    ) {}
}
