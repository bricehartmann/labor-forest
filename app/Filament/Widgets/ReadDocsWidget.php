<?php

namespace App\Filament\Widgets;

use App\Concerns\Filament\Pages\HasResultNotificationOperations;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Process;

class ReadDocsWidget extends Widget implements HasActions, HasSchemas
{
    use HasResultNotificationOperations;
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected static ?int $sort = 2;

    protected string $view = 'filament.widgets.read-docs-widget';

    public function readDocsAction(): Action
    {
        return Action::make('readDocs')
            ->label('Read the Docs')
            ->color('info')
            ->action(function () {
                Process::run(['open', config('app.docs_url')]);
            });
    }
}
