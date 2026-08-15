<?php

namespace App\Filament\Pages;

use Filament\Notifications\Notification;

class Dashboard extends \Filament\Pages\Dashboard
{
    public function mount(): void
    {
        if (session()->has('error')) {
            Notification::make()
                ->danger()
                ->title(request()->session()->get('error'))
                ->send();
        }
    }
}
