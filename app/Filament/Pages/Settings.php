<?php

namespace App\Filament\Pages;

use App\Data\SettingsData;
use App\Exceptions\InvalidSettingsFile;
use App\Rules\ValidVariables;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class Settings extends Page
{
    public ?array $data = [];

    protected string $view = 'filament.pages.settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Cog8Tooth;

    public function mount(): void
    {
        try {
            $settings = app(SettingsService::class)->loadSettings();
        } catch (InvalidSettingsFile $e) {
            Notification::make()
                ->danger()
                ->title('Your settings file is invalid')
                ->body($e->messagesAsString())
                ->persistent()
                ->send();

            $settings = new SettingsData;
        }

        $this->form->fill($settings->toArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('command_open_terminal')
                    ->label('Open terminal command')
                    ->placeholder('open "{{ WORKSPACE_DIR }}" -a iterm')
                    ->rules([new ValidVariables]),
                TextInput::make('command_open_ide')
                    ->label('Open IDE command')
                    ->placeholder('open "{{ WORKSPACE_DIR }}" -a phpstorm')
                    ->rules([new ValidVariables]),
                TextInput::make('command_open_browser')
                    ->label('Open browser command')
                    ->placeholder('open "{{ ENV_APP_URL }}"')
                    ->rules([new ValidVariables]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $this->form->getState();
    }
}
