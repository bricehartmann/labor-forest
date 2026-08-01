<?php

namespace App\Filament\Widgets;

use App\Concerns\Filament\Pages\HasResultNotificationOperations;
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
    use HasResultNotificationOperations;
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected string $view = 'filament.widgets.add-project-widget';

    public function addProjectAction(): Action
    {
        return Action::make('addProject')
            ->label('Select project directory')
            ->color('primary')
            ->action(function () {
                $path = Dialog::new()
                    ->title('Select project directory')
                    ->folders()
                    ->open();

                if (! $path) {
                    return;
                }

                static::resultNotificationOperation(
                    callback: function () use ($path) {
                        app(ProjectsService::class)->addProject($path);
                    },
                    successTitle: 'Project added',
                    failureBody: fn (Throwable $th) => $th->getMessage(),
                );
            });
    }
}
