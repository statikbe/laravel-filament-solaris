<?php

namespace Statikbe\FilamentSolaris\Support\Batch\Handlers;

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;
use Statikbe\FilamentSolaris\Support\Batch\BatchCompletionHandler;
use Statikbe\FilamentSolaris\Support\Batch\BatchSummary;

/**
 * Framework-default completion handler: a Filament notification summarising the
 * run. Inline (a live request) flashes a session toast; queued (worker, no
 * session) sends a database notification to the initiating user, defensively
 * falling back to a log line when no notifiable can be resolved.
 */
final class NotifyOnBatchCompletion implements BatchCompletionHandler
{
    public function handle(BatchSummary $summary): void
    {
        if (! config('filament-solaris.batch_tracking.notify_on_completion', true)) {
            return;
        }

        $notification = $this->buildNotification($summary);

        if (! $summary->queued) {
            $notification->send();

            return;
        }

        $this->sendToRunUser($notification, $summary);
    }

    protected function buildNotification(BatchSummary $summary): Notification
    {
        if ($summary->status === BatchRunStatus::Failed) {
            return Notification::make()
                ->title(filament_solaris_trans('notifications.batch_failed', [
                    'count' => $summary->total(),
                    'failed' => $summary->failed,
                ]))
                ->danger();
        }

        if ($summary->failed > 0) {
            return Notification::make()
                ->title(filament_solaris_trans('notifications.batch_partial_failure', [
                    'count' => $summary->succeeded,
                    'failed' => $summary->failed,
                ]))
                ->warning();
        }

        return Notification::make()
            ->title(filament_solaris_trans('notifications.batch_completed', ['count' => $summary->succeeded]))
            ->success();
    }

    protected function sendToRunUser(Notification $notification, BatchSummary $summary): void
    {
        try {
            $run = $summary->runId === null ? null : SolarisBatchRun::find($summary->runId);
            $userId = $run?->user_id;
            $userModel = config('auth.providers.users.model');
            $notifiable = ($userId === null || $userModel === null) ? null : $userModel::find($userId);

            if ($notifiable === null) {
                throw new \RuntimeException('no resolvable notifiable for run '.$summary->runId);
            }

            $notification->sendToDatabase($notifiable);
        } catch (\Throwable $e) {
            Log::warning('FilamentSolaris: batch completion notification could not be delivered ('.$e->getMessage().'); '
                .'run '.$summary->runId.' — '.$summary->succeeded.' ok, '.$summary->failed.' failed.');
        }
    }
}
