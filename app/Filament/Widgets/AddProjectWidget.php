<?php

namespace App\Filament\Widgets;

use App\Concerns\Filament\Pages\HasResultNotificationOperations;
use App\Concerns\Filament\Widgets\HasProjectsLoadError;
use App\Enums\SessionKey;
use App\Filament\Pages\Project;
use App\Services\ProjectsService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Native\Desktop\Dialog;
use Throwable;

class AddProjectWidget extends Widget implements HasActions, HasSchemas
{
    use HasProjectsLoadError;
    use HasResultNotificationOperations;
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected static ?int $sort = 3;

    protected string $view = 'filament.widgets.add-project-widget';

    public static function canView(): bool
    {
        return blank(static::projectsLoadErrorMessage());
    }

    public function addProjectAction(): Action
    {
        return Action::make('addProject')
            ->label('Add project')
            ->color('success')
            ->action(function () {
                $path = $this->selectProjectDirectory();

                if (! $path) {
                    return;
                }

                static::resultNotificationOperation(
                    callback: function () use ($path) {
                        $project = app(ProjectsService::class)->addProject($path);

                        session()->put(SessionKey::PROJECT_CREATED->value, $project->uuid);

                        $this->redirect(Project::getUrl([
                            'uuid' => $project->uuid,
                        ]));
                    },
                    successTitle: 'Project added',
                    failureBody: fn (Throwable $th) => $th->getMessage(),
                );
            });
    }

    /**
     * The directory picker, isolated so a test can choose a path without opening a native dialog.
     */
    protected function selectProjectDirectory(): ?string
    {
        return Dialog::new()
            ->title('Select Project Directory')
            ->folders()
            ->defaultPath(getenv('HOME') ?: '/')
            ->open();
    }
}
