<?php

namespace App\Filament\Pages;

use Filament\Notifications\Notification;

class Dashboard extends \Filament\Pages\Dashboard
{
    /**
     * The error arrives on the query string because the CLI listener runs in the native event
     * request, which shares no session with this window.
     */
    public function mount(): void
    {
        $error = request()->query('error');

        if (is_string($error) && $error !== '') {
            Notification::make()
                ->danger()
                ->title($error)
                ->send();
        }
    }
}
