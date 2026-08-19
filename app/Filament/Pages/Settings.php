<?php

namespace App\Filament\Pages;

use App\Concerns\Filament\Pages\HasResultNotificationOperations;
use App\Concerns\Filament\Pages\NormalizesLaunchCommands;
use App\Data\McpServerHealthData;
use App\Data\SettingsData;
use App\Enums\McpEndpoint;
use App\Enums\Variable;
use App\Exceptions\InvalidSettingsFile;
use App\Exceptions\McpServerUnhealthy;
use App\Rules\ValidVariables;
use App\Services\McpService;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use Throwable;

class Settings extends Page
{
    use HasResultNotificationOperations;
    use NormalizesLaunchCommands;

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
                                TextInput::make('workflow_step_timeout_seconds')
                                    ->label('Timeout per step')
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
                Section::make('MCP')
                    ->description('Configure the local MCP server.')
                    ->extraAttributes(['class' => 'h-full [&>.fi-section]:flex-1'])
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Toggle::make('mcp_enabled')
                                    ->label('Enable MCP')
                                    ->inline(false)
                                    ->live(),
                                TextInput::make('mcp_port')
                                    ->disabled(fn (Get $get) => ! $get('mcp_enabled'))
                                    ->label('MCP local port')
                                    ->numeric()
                                    ->minValue(1024)
                                    ->maxValue(49151)
                                    ->required()
                                    // The endpoint below is rebuilt from this value, so it
                                    // has to reach the server before the form is saved.
                                    ->live(onBlur: true),
                                Actions::make([
                                    Action::make('test_mcp_connection')
                                        ->label('Test connection')
                                        ->button()
                                        ->icon(Heroicon::Bolt)
                                        ->action(fn (Get $get) => $this->testMcpConnection($get->integer('mcp_port'))),
                                ])
                                    ->alignEnd()
                                    ->verticallyAlignEnd(),
                            ]),
                        TextEntry::make('mcp_claude_command')
                            ->label('Add to Claude Code')
                            ->helperText('Run this in a terminal to register the server. Click to copy.')
                            ->visible(fn (Get $get): bool => (bool) $get('mcp_enabled') && filled($get('mcp_port')))
                            ->state(fn (Get $get): string => McpEndpoint::LABORFOREST->claudeAddCommand($get->integer('mcp_port')))
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->copyMessage('Command copied'),
                    ]),
                Section::make('Launch commands')
                    ->description(new HtmlString('Commands that are run to launch an application with a specific workspace\'s directory or local site.<br/>Each command can be overridden at the project level.'))
                    ->schema([
                        TextInput::make('command_launch_terminal')
                            ->label('Launch terminal command')
                            ->helperText('The command to run to launch a terminal with a working directory of a specific workspace.')
                            ->placeholder('open "{{ WORKSPACE_DIR }}" -a iterm')
                            ->nullable()
                            ->dehydrateStateUsing(static::blankToNull(...))
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
                            ->dehydrateStateUsing(static::blankToNull(...))
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
                            ->dehydrateStateUsing(static::blankToNull(...))
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

        $previousSettings = rescue(fn () => app(SettingsService::class)->loadSettings());

        static::resultNotificationOperation(
            callback: function () use ($data) {
                $settingsService = app(SettingsService::class);

                $settingsService->saveSettings(SettingsData::from([
                    ...$settingsService->loadSettings()->toArray(),
                    ...$data,
                ]));
            },
            successTitle: 'Settings saved',
        );

        $this->applyTheme((bool) ($data['dark_mode'] ?? false));

        $this->syncMcpServer($previousSettings, $data);
    }

    /**
     * Bring the MCP server in line with the settings that were just written.
     *
     * Only a real change acts, so saving an unrelated setting leaves a healthy server alone. A
     * changed port has to go through a stop and a start rather than a restart, because the runtime
     * replays the argv a process was started with and would keep serving the old port.
     *
     * @param  array<string, mixed>  $data
     */
    protected function syncMcpServer(?SettingsData $previousSettings, array $data): void
    {
        $enabled = (bool) ($data['mcp_enabled'] ?? false);
        $port = (int) ($data['mcp_port'] ?? 0);

        $wasEnabled = $previousSettings?->mcp_enabled ?? false;

        $operation = match (true) {
            $enabled && ! $wasEnabled => fn (McpService $mcp) => $mcp->startMcpServer(),
            $enabled && $port !== $previousSettings?->mcp_port => fn (McpService $mcp) => $mcp->restartMcpServer(),
            ! $enabled && $wasEnabled => fn (McpService $mcp) => $mcp->stopMcpServer(),
            default => null,
        };

        if ($operation === null) {
            return;
        }

        static::resultNotificationOperation(
            callback: fn () => $operation(app(McpService::class)),
            successTitle: null,
            failureTitle: 'The MCP server could not be updated.',
            failureBody: fn (Throwable $th): string => $th->getMessage(),
        );
    }

    /**
     * Report whether the endpoint the form currently names answers as this application's MCP server.
     *
     * The port comes from the form rather than from the saved settings, because the URL shown beside
     * the button is built from that same value — a check that quietly probed a different port would
     * be worse than no check at all. An unsaved port therefore reports what a client would find right
     * now, which for a port nothing is serving yet is nothing.
     *
     * Reporting is switched off: every throwable this can produce is the diagnosis the user asked
     * for rather than a defect, and a user retrying a stopped server would otherwise fill the log.
     */
    protected function testMcpConnection(int $port): void
    {
        static::resultNotificationOperation(
            callback: fn (): McpServerHealthData => app(McpService::class)->checkMcpServer($port),
            report: false,
            successTitle: fn (McpServerHealthData $health): string => $health->status->title(),
            successBody: fn (McpServerHealthData $health): string => $health->description(),
            successIcon: fn (McpServerHealthData $health): BackedEnum => $health->status->icon(),
            failureTitle: fn (Throwable $th): string => $th instanceof McpServerUnhealthy
                ? $th->status->title()
                : 'Whoops! Something went wrong.',
            failureBody: fn (Throwable $th): string => $th->getMessage(),
            failureIcon: fn (Throwable $th): BackedEnum => $th instanceof McpServerUnhealthy
                ? $th->status->icon()
                : Heroicon::XCircle,
        );
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
