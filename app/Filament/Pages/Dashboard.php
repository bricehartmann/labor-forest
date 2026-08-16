<?php

namespace App\Filament\Pages;

use App\Concerns\Filament\Pages\HasQueryStringNotification;

class Dashboard extends \Filament\Pages\Dashboard
{
    use HasQueryStringNotification;

    public function mount(): void
    {
        $this->sendQueryStringNotification();
    }
}
