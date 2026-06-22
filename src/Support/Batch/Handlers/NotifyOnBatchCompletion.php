<?php

namespace Statikbe\FilamentSolaris\Support\Batch\Handlers;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Support\Batch\BatchCompletionHandler;
use Statikbe\FilamentSolaris\Support\Batch\BatchFailureReport;
use Statikbe\FilamentSolaris\Support\Batch\BatchReportFormat;
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
        if (! config('filament-solaris.batch_tracking.completion.notify', true)) {
            return;
        }

        $notification = $this->buildNotification($summary);

        // Tracked/queued runs persist to the database notification (the bell), which
        // carries the download actions. Inline runs additionally flash for immediacy.
        if ($summary->runId !== null) {
            $this->sendToRunUser($notification, $summary);
        }

        if (! $summary->queued) {
            $notification->send();
        }
    }

    protected function buildNotification(BatchSummary $summary): Notification
    {
        if ($summary->status === BatchRunStatus::Failed) {
            // "X of Y not processed" — Y is succeeded+failed (total()); discarded
            // outputs are excluded intentionally (they're a separate, AI-side counter).
            $notification = Notification::make()
                ->title(filament_solaris_trans('notifications.batch_failed', [
                    'count' => $summary->total(),
                    'failed' => $summary->failed,
                ]))
                ->danger();
        } elseif ($summary->failed > 0) {
            $notification = Notification::make()
                ->title(filament_solaris_trans('notifications.batch_partial_failure', [
                    'count' => $summary->succeeded,
                    'failed' => $summary->failed,
                ]))
                ->warning();
        } else {
            $notification = Notification::make()
                ->title(filament_solaris_trans('notifications.batch_completed', ['count' => $summary->succeeded]))
                ->success();
        }

        if ($this->shouldAttachReport($summary)) {
            $runId = (string) $summary->runId;
            $notification->actions([
                Action::make('download_failures_csv')
                    ->label(filament_solaris_trans('notifications.download_failures_csv'))
                    ->url(BatchFailureReport::downloadUrl($runId, BatchReportFormat::Csv), shouldOpenInNewTab: true),
                Action::make('download_failures_xlsx')
                    ->label(filament_solaris_trans('notifications.download_failures_xlsx'))
                    ->url(BatchFailureReport::downloadUrl($runId, BatchReportFormat::Xlsx), shouldOpenInNewTab: true),
            ]);
        }

        return $notification;
    }

    protected function shouldAttachReport(BatchSummary $summary): bool
    {
        if ($summary->failed === 0 || $summary->runId === null) {
            return false;
        }

        // Per-action override (->withFailureReport()) is stashed in run.meta at
        // dispatch; fall back to the global config for runs without it.
        return (bool) ($summary->run()?->meta['attach_failure_report']
            ?? config('filament-solaris.batch_tracking.completion.failure_report', true));
    }

    protected function sendToRunUser(Notification $notification, BatchSummary $summary): void
    {
        try {
            $notifiable = $summary->run()?->getUser();

            if ($notifiable === null) {
                throw new \RuntimeException('no resolvable notifiable for run '.$summary->runId);
            }

            $notification->sendToDatabase($notifiable);
        } catch (\Throwable $e) {
            // Only the queued path relies on database delivery; inline still flashes.
            if ($summary->queued) {
                Log::warning('FilamentSolaris: batch completion notification could not be delivered ('.$e->getMessage().'); '
                    .'run '.$summary->runId.' — '.$summary->succeeded.' ok, '.$summary->failed.' failed.');
            }
        }
    }
}
