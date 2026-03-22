<?php

namespace Statikbe\FilamentSolaris\Support;

use Filament\Notifications\Notification;
use Illuminate\Support\Arr;

class SolarisNotification
{
    /**
     * Send appropriate notification based on filled/failed fields.
     *
     * @param  array<string>  $filledLabels
     * @param  array<string>  $failedLabels
     */
    public static function sendResultNotifications(array $filledLabels, array $failedLabels): void
    {
        if (! empty($filledLabels) && empty($failedLabels)) {
            Notification::make()
                ->title(filament_solaris_trans('notifications.success', ['fields' => static::formatFieldList($filledLabels)]))
                ->success()
                ->send();
        } elseif (! empty($filledLabels) && ! empty($failedLabels)) {
            Notification::make()
                ->title(filament_solaris_trans_choice('notifications.partial_failure', count($failedLabels), ['fields' => static::formatFieldList($failedLabels)]))
                ->warning()
                ->send();
        } else {
            Notification::make()
                ->title(filament_solaris_trans('notifications.error'))
                ->danger()
                ->send();
        }
    }

    /**
     * Format a list of field labels for display in notifications.
     *
     * @param  array<string>  $labels
     */
    public static function formatFieldList(array $labels): string
    {
        $quoted = array_map(fn (string $label): string => "'{$label}'", $labels);

        return Arr::join($quoted, ', ', ' & ');
    }
}
