<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class AppVersionWidget extends Widget
{
    protected static ?int $sort = 1;

    protected string $view = 'filament.widgets.app-version-widget';

    protected function getViewData(): array
    {
        return ['appVersion' => config('nativephp.version')];
    }
}
