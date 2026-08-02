<?php

namespace App\Filament\Pages;

use App\Concerns\Filament\Pages\HasResultNotificationOperations;
use App\Data\SettingsData;
use App\Enums\Variable;
use App\Exceptions\InvalidSettingsFile;
use App\Rules\ValidVariables;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;

class Settings extends Page
{
    use HasResultNotificationOperations;

    public ?array $data = [];

    #[Locked]
    public ?string $loadedInvalidMessage = null;

    protected string $view = 'filament.pages.settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Cog8Tooth;

    public function mount(): void
    {
        try {
            $settings = app(SettingsService::class)->loadSettings();
        } catch (InvalidSettingsFile $e) {
            $settings = new SettingsData;
            $this->loadedInvalidMessage = $e->messagesAsString();
        }

        $this->form->fill($settings->toArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Notifications')
                    ->description('Choose if you would like to be notified when a Workflow run concludes.')
                    ->schema([
                        Checkbox::make('desktop_notifications')
                            ->label('Enable desktop notifications'),
                    ]),
                Section::make('Open commands')
                    ->description('Commands that are run to open a specific workspace\'s directory or local site.')
                    ->schema([
                        TextInput::make('command_open_terminal')
                            ->label('Open terminal command')
                            ->helperText('The command to run to open a terminal with a working directory of a specific workspace.')
                            ->placeholder('open "{{ WORKSPACE_DIR }}" -a iterm')
                            ->rules([new ValidVariables])
                            ->suffixActions([
                                Action::make('command_open_terminal_example')
                                    ->label('Show example')
                                    ->button()
                                    ->modal()
                                    ->modalWidth(Width::Medium)
                                    ->modalHeading('Example command')
                                    ->modalDescription(new HtmlString('<code>open "{{ WORKSPACE_DIR }}" -a iterm</code>'))
                                    ->modalSubmitActionLabel('Use example')
                                    ->modalCancelActionLabel('Close')
                                    ->modalFooterActionsAlignment(Alignment::End)
                                    ->action(fn (Set $set) => $set('command_open_terminal', 'open "{{ WORKSPACE_DIR }}" -a iterm')),
                            ]),
                        TextInput::make('command_open_ide')
                            ->label('Default open IDE command')
                            ->helperText('The command to run to open a workspace in an IDE. Can be changed at the project level.')
                            ->placeholder('open "{{ WORKSPACE_DIR }}" -a phpstorm')
                            ->rules([new ValidVariables])
                            ->suffixActions([
                                Action::make('command_open_ide_example')
                                    ->label('Show example')
                                    ->button()
                                    ->modal()
                                    ->modalWidth(Width::Medium)
                                    ->modalHeading('Example command')
                                    ->modalDescription(new HtmlString('<code>open "{{ WORKSPACE_DIR }}" -a phpstorm</code>'))
                                    ->modalSubmitActionLabel('Use example')
                                    ->modalCancelActionLabel('Close')
                                    ->modalFooterActionsAlignment(Alignment::End)
                                    ->action(fn (Set $set) => $set('command_open_ide', 'open "{{ WORKSPACE_DIR }}" -a phpstorm')),
                            ]),
                        TextInput::make('command_open_browser')
                            ->label('Default open browser command')
                            ->helperText('The command to run to open a browser for a specific workspace\'s local site. Can be changed at the project level.')
                            ->placeholder('open "{{ ENV_APP_URL }}"')
                            ->rules([new ValidVariables])
                            ->suffixActions([
                                Action::make('command_open_browser_example')
                                    ->label('Show example')
                                    ->button()
                                    ->modal()
                                    ->modalWidth(Width::Medium)
                                    ->modalHeading('Example command')
                                    ->modalDescription(new HtmlString('<code>open "{{ ENV_APP_URL }}"</code>'))
                                    ->modalSubmitActionLabel('Use example')
                                    ->modalCancelActionLabel('Close')
                                    ->modalFooterActionsAlignment(Alignment::End)
                                    ->action(fn (Set $set) => $set('command_open_browser', 'open "{{ ENV_APP_URL }}"')),
                            ]),
                        KeyValueEntry::make('variables')
                            ->label('Available variables')
                            ->keyLabel('Variable')
                            ->valueLabel('Example')
                            ->state([
                                ...collect(Variable::cases())->mapWithKeys(fn (Variable $var) => ['{{ '.$var->value.' }}' => $var->example()]),
                                '{{ ENV_ANY_KEY }}' => '(any key from .env prefixed by "ENV_")',
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        static::resultNotificationOperation(
            callback: fn () => app(SettingsService::class)->saveSettings(SettingsData::from($data)),
            successTitle: 'Settings saved',
        );
    }
}
