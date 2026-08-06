<?php

namespace App\Concerns\Filament\Widgets;

use App\Services\ProjectsService;
use Throwable;

trait HasProjectsLoadError
{
    /**
     * The message describing why the projects file could not be loaded, if it could not be loaded.
     */
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
