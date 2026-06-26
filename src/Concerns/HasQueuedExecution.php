<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Closure;
use Filament\Notifications\Notification;
use Statikbe\FilamentSolaris\Generation\AiGenerator;

/**
 * Queued-execution opt-in for AiGenerateAction: the `->queued()` flag, its
 * Filament-DI evaluation, and the "started" notification. The dispatch machinery
 * itself (run config snapshot, chunk descriptors, prompt rendering, Bus::batch)
 * lives in {@see AiGenerator::runQueued()} —
 * the action just builds the generator and chooses the queued terminal.
 */
trait HasQueuedExecution
{
    protected bool|Closure $queued = false;

    /**
     * Run the records loop on the queue (Bus::batch of per-chunk jobs) instead of
     * inline in the request. Opt-in; requires ->forModel() + ->createRecords()/
     * ->updateRecords().
     */
    public function queued(bool|Closure $queued = true): static
    {
        $this->queued = $queued;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $userInput
     */
    protected function isQueued(array $userInput = []): bool
    {
        return (bool) ($this->queued instanceof Closure
            ? $this->evaluate($this->queued, ['userInput' => $userInput])
            : $this->queued);
    }

    protected function sendQueuedStartedNotification(): void
    {
        Notification::make()
            ->title(filament_solaris_trans('notifications.batch_queued'))
            ->success()
            ->send();
    }
}
