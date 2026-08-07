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
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
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
                Grid::make(2)
                    ->schema([
                        Section::make('Workflows')
                            ->description('Configure how workflows run on your machine.')
                            ->extraAttributes(['class' => 'h-full [&>.fi-section]:flex-1'])
                            ->schema([
                                TextInput::make('workflow_timeout_seconds')
                                    ->label('Timeout')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(3600)
                                    ->required()
                                    ->suffix('seconds'),
                            ]),
                        Section::make('Dark mode')
                            ->description('Choose if you would like to use the dark theme.')
                            ->extraAttributes(['class' => 'h-full [&>.fi-section]:flex-1'])
                            ->schema([
                                Toggle::make('dark_mode')
                                    ->inline(false)
                                    ->label('Enable dark mode'),
                            ]),
                    ]),
                Section::make('Launch commands')
                    ->description(new HtmlString('Commands that are run to launch an application with a specific workspace\'s directory or local site.<br/>Each command can be overridden at the project level.'))
                    ->schema([
                        TextInput::make('command_launch_terminal')
                            ->label('Launch terminal command')
                            ->helperText('The command to run to launch a terminal with a working directory of a specific workspace.')
                            ->placeholder('open "{{ WORKSPACE_DIR }}" -a iterm')
                            ->nullable()
                            ->rules([new ValidVariables])
                            ->suffixActions([
                                Action::make('command_launch_terminal_example')
                                    ->label('Show example')
                                    ->button()
                                    ->modal()
                                    ->modalWidth(Width::Medium)
                                    ->modalHeading('Example command to launch iTerm2')
                                    ->modalDescription(new HtmlString('<code>open "{{ WORKSPACE_DIR }}" -a iterm</code>'))
                                    ->modalSubmitActionLabel('Use example')
                                    ->modalCancelActionLabel('Close')
                                    ->modalFooterActionsAlignment(Alignment::End)
                                    ->action(fn (Set $set) => $set('command_launch_terminal', 'open "{{ WORKSPACE_DIR }}" -a iterm')),
                            ]),
                        TextInput::make('command_launch_ide')
                            ->label('Launch IDE command')
                            ->helperText('The command to run to launch a workspace directory in an IDE.')
                            ->placeholder('open "{{ WORKSPACE_DIR }}" -a phpstorm')
                            ->nullable()
                            ->rules([new ValidVariables])
                            ->suffixActions([
                                Action::make('command_launch_ide_example')
                                    ->label('Show example')
                                    ->button()
                                    ->modal()
                                    ->modalWidth(Width::Medium)
                                    ->modalHeading('Example command to launch PhpStorm')
                                    ->modalDescription(new HtmlString('<code>open "{{ WORKSPACE_DIR }}" -a phpstorm</code>'))
                                    ->modalSubmitActionLabel('Use example')
                                    ->modalCancelActionLabel('Close')
                                    ->modalFooterActionsAlignment(Alignment::End)
                                    ->action(fn (Set $set) => $set('command_launch_ide', 'open "{{ WORKSPACE_DIR }}" -a phpstorm')),
                            ]),
                        TextInput::make('command_launch_browser')
                            ->label('Launch browser command')
                            ->helperText('The command to run to launch a browser for a specific workspace\'s local site.')
                            ->placeholder('open "{{ ENV_APP_URL }}"')
                            ->nullable()
                            ->rules([new ValidVariables])
                            ->suffixActions([
                                Action::make('command_launch_browser_example')
                                    ->label('Show example')
                                    ->button()
                                    ->modal()
                                    ->modalWidth(Width::Medium)
                                    ->modalHeading('Example command to launch the default browser')
                                    ->modalDescription(new HtmlString('<code>open "{{ ENV_APP_URL }}"</code>'))
                                    ->modalSubmitActionLabel('Use example')
                                    ->modalCancelActionLabel('Close')
                                    ->modalFooterActionsAlignment(Alignment::End)
                                    ->action(fn (Set $set) => $set('command_launch_browser', 'open "{{ ENV_APP_URL }}"')),
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

        $this->applyTheme((bool) ($data['dark_mode'] ?? false));
    }

    /**
     * Flip the theme on the current page, since the panel only reads the
     * setting when a request boots.
     */
    protected function applyTheme(bool $darkMode): void
    {
        $theme = $darkMode ? 'dark' : 'light';

        $this->js(<<<JS
            window.dispatchEvent(new CustomEvent('theme-changed', {
                detail: '$theme',
            }))
        JS);
    }
}
