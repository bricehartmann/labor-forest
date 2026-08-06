<?php

namespace App\Filament\Widgets;

use App\Services\ProjectsService;
use Filament\Widgets\Widget;
use Throwable;

class ProjectsLoadErrorWidget extends Widget
{
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

    protected static function projectsLoadErrorMessage(): ?string
    {
        try {
            app(ProjectsService::class)->loadProjects();

            return null;
        } catch (Throwable $th) {
            return $th->getMessage();
        }
    }
}
