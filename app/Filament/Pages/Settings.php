<?php

namespace App\Filament\Pages;

use App\Services\SettingsService;
use BackedEnum;
use Filament\Forms\Components\TextInput;
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
        $this->form->fill(app(SettingsService::class)->loadSettings()->toArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('command_open_terminal')
                    ->label('Open terminal command')
                    ->placeholder('open "{{ WORKSPACE_DIR }}" -a iterm'),
                TextInput::make('command_open_ide')
                    ->label('Open IDE command')
                    ->placeholder('open "{{ WORKSPACE_DIR }}" -a phpstorm'),
                TextInput::make('command_open_browser')
                    ->label('Open browser command')
                    ->placeholder('open "{{ ENV_APP_URL }}"'),
            ])
            ->statePath('data');
    }

    public function save(): void {}
}
