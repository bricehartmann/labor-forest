<?php

namespace App\Concerns\Filament\Pages;

use BackedEnum;
use Closure;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

trait HasResultNotificationOperations
{
    protected static function resultNotificationOperation(
        Closure $callback,
        bool $report = true,
        string|Closure|null $successTitle = 'Success',
        string|Closure|null $successBody = null,
        BackedEnum|Closure $successIcon = Heroicon::CheckCircle,
        string|Closure|null $failureTitle = 'Whoops! Something went wrong.',
        string|Closure|null $failureBody = null,
        BackedEnum|Closure $failureIcon = Heroicon::XCircle,
    ): void {
        try {
            $data = $callback();

            static::sendNotification(
                isSuccess: true,
                title: static::callOrDefault($successTitle, $data),
                body: static::callOrDefault($successBody, $data),
                icon: static::callOrDefault($successIcon, $data),
            );
        } catch (Throwable $th) {
            if ($report) {
                report($th);
            }

            static::sendNotification(
                isSuccess: false,
                title: static::callOrDefault($failureTitle, $th),
                body: static::callOrDefault($failureBody, $th),
                icon: static::callOrDefault($failureIcon, $th),
            );
        }
    }

    private static function callOrDefault(string|BackedEnum|Closure|null $callbackOrDefault, mixed $dataOrThrowable = null): mixed
    {
        return match (true) {
            $dataOrThrowable !== null && $callbackOrDefault instanceof Closure => $callbackOrDefault($dataOrThrowable),
            $callbackOrDefault instanceof Closure => $callbackOrDefault(),
            default => $callbackOrDefault,
        };
    }

    private static function sendNotification(bool $isSuccess, ?string $title, ?string $body, ?BackedEnum $icon): void
    {
        if (! $title) {
            return;
        }

        Notification::make()
            ->when(
                value: $isSuccess,
                callback: fn (Notification $notification) => $notification->success(),
                default: fn (Notification $notification) => $notification->danger(),
            )
            ->title($title)
            ->body($body)
            ->icon($icon)
            ->send();
    }
}
