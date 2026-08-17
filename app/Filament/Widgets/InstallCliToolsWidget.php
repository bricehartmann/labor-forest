<?php

namespace App\Filament\Widgets;

use App\Concerns\Filament\Pages\HasResultNotificationOperations;
use App\Services\CliToolsService;
use App\Services\SettingsService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\File;
use Native\Desktop\Dialog;
use Throwable;

class InstallCliToolsWidget extends Widget implements HasActions, HasSchemas
{
    use HasResultNotificationOperations;
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected static ?int $sort = 4;

    protected string $view = 'filament.widgets.cli-tools-widget';

    public function installCliToolsAction(): Action
    {
        return Action::make('installCliTools')
            ->label(fn (): string => $this->cliToolsInstalled() ? 'Reinstall CLI tools' : 'Install CLI tools')
            ->color('primary')
            ->action(function () {
                $path = $this->selectCliToolsPath();

                if (! $path) {
                    return;
                }

                static::resultNotificationOperation(
                    callback: fn () => app(CliToolsService::class)->installCliTools($path),
                    successTitle: 'CLI tools installed',
                    failureBody: fn (Throwable $th) => $th->getMessage(),
                );
            });
    }

    /**
     * Whether the tools have been installed before, which decides the button's label.
     *
     * Fails closed to `Install CLI tools`, so an unreadable settings file cannot break the render.
     */
    protected function cliToolsInstalled(): bool
    {
        return rescue(fn () => app(SettingsService::class)->loadSettings()->cli_tools_installed, false);
    }

    /**
     * The directory picker, isolated so a test can choose a path without opening a native dialog.
     */
    protected function selectCliToolsPath(): ?string
    {
        return Dialog::new()
            ->title('Select directory for CLI tools')
            ->folders()
            ->when(File::isDirectory('/usr/local/bin'), fn (Dialog $dialog) => $dialog->defaultPath('/usr/local/bin'))
            ->open();
    }
}
