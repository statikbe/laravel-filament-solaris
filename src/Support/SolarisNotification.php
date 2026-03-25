<?php

namespace Statikbe\FilamentSolaris\Support;

use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

class SolarisNotification
{
    /**
     * Handle an AI exception by reporting it and sending a danger notification.
     */
    public static function sendAiErrorNotification(AiException $e): void
    {
        report($e);

        $translationKey = match (true) {
            $e instanceof RateLimitedException => 'notifications.rate_limited',
            $e instanceof ProviderOverloadedException => 'notifications.overloaded',
            default => 'notifications.error',
        };

        Notification::make()
            ->title(filament_solaris_trans($translationKey))
            ->danger()
            ->send();
    }

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
     * Handle an AI exception for image generation.
     */
    public static function sendImageGenerationErrorNotification(AiException $e): void
    {
        report($e);

        $translationKey = match (true) {
            $e instanceof RateLimitedException => 'notifications.image_generation_rate_limited',
            $e instanceof ProviderOverloadedException => 'notifications.overloaded',
            default => 'notifications.image_generation_error',
        };

        Notification::make()
            ->title(filament_solaris_trans($translationKey))
            ->danger()
            ->send();
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
