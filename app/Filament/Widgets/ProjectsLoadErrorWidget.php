<?php

namespace App\Filament\Widgets;

use App\Concerns\Filament\Widgets\HasProjectsLoadError;
use Filament\Widgets\Widget;

class ProjectsLoadErrorWidget extends Widget
{
    use HasProjectsLoadError;

    protected string $view = 'filament.widgets.projects-load-error-widget';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public ?string $loadedInvalidMessage = null;

    public function mount(): void
    {
        $this->loadedInvalidMessage = static::projectsLoadErrorMessage();
    }

    public static function canView(): bool
    {
        return filled(static::projectsLoadErrorMessage());
    }
}
