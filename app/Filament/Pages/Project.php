<?php

namespace App\Filament\Pages;

use App\Concerns\Filament\Pages\HasResultNotificationOperations;
use App\Data\ProjectData;
use App\Data\WorkspaceData;
use App\Enums\Directory;
use App\Enums\Variable;
use App\Enums\WorkspaceStatus;
use App\Exceptions\InvalidProjectsFile;
use App\Rules\ValidVariables;
use App\Services\LaunchService;
use App\Services\ProjectsService;
use App\Services\SettingsService;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Throwable;

class Project extends Page implements HasActions, HasSchemas, HasTable
{
    use HasResultNotificationOperations;
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public ?string $loadedInvalidMessage = null;

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public array $project = [];

    #[Locked]
    public array $workspaces = [];

    protected string $view = 'filament.pages.project';

    #[Computed]
    public function projectData(): ProjectData
    {
        return ProjectData::from($this->project);
    }

    public function mount(string $uuid): void
    {
        $this->loadProjectData($uuid);
    }

    protected function loadProjectData(string $uuid): void
    {
        unset($this->projectData);

        $projectService = app(ProjectsService::class);

        try {
            $this->project = $projectService->loadProject($uuid)->toArray();
            $this->workspaces = $projectService->loadProjectWorkspaces($this->projectData->path)->toArray();
        } catch (InvalidProjectsFile $e) {
            $this->loadedInvalidMessage = $e->messagesAsString();
        } catch (Exception $e) {
            $this->loadedInvalidMessage = $e->getMessage();
        }
    }

    public static function getSlug($panel = null): string
    {
        return '/projects/{uuid}';
    }

    public function getHeading(): string
    {
        return $this->projectData->dirName();
    }

    public function removeAction(): Action
    {
        return Action::make('remove')
            ->label('Remove project')
            ->button()
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Remove project')
            ->modalDescription(new HtmlString('Are you sure you want to remove this project?<br/><br/>This action will not remove the <code class="text-red-600">'.Directory::BASE->value.'</code> directory.'))
            ->modalSubmitActionLabel('Remove')
            ->modalCancelActionLabel('Cancel')
            ->modalFooterActionsAlignment(Alignment::End)
            ->action(function () {
                static::resultNotificationOperation(
                    callback: function () {
                        app(ProjectsService::class)->removeProject($this->projectData->uuid);

                        $this->redirect('/');
                    },
                    successTitle: 'Project removed',
                    failureBody: fn (Throwable $th) => $th->getMessage(),
                );
            });
    }

    public function editLaunchCommandsAction(): Action
    {
        $settings = app(SettingsService::class)->loadSettings();

        return Action::make('editLaunchCommands')
            ->label('Edit launch commands')
            ->button()
            ->modal()
            ->modalHeading('Edit Launch Commands')
            ->modalDescription(new HtmlString('Specify the commands that are run to launch an application with a specific workspace\'s directory or local site.<br/>Leave fields blank to use the global defaults.'))
            ->modalSubmitActionLabel('Save')
            ->modalCancelActionLabel('Cancel')
            ->modalFooterActionsAlignment(Alignment::End)
            ->fillForm(fn () => [
                'command_launch_terminal' => $this->projectData->command_launch_terminal,
                'command_launch_ide' => $this->projectData->command_launch_ide,
                'command_launch_browser' => $this->projectData->command_launch_browser,
            ])
            ->schema([
                TextInput::make('command_launch_terminal')
                    ->label('Launch terminal command')
                    ->helperText('The command to run to launch a terminal with a working directory of a specific workspace.')
                    ->placeholder($settings->command_launch_terminal ?? 'open "{{ WORKSPACE_DIR }}" -a iterm')
                    ->nullable()
                    ->rules([new ValidVariables]),
                TextInput::make('command_launch_ide')
                    ->label('Default launch IDE command')
                    ->helperText('The command to run to launch a workspace directory in an IDE.')
                    ->placeholder($settings->command_launch_ide ?? 'open "{{ WORKSPACE_DIR }}" -a phpstorm')
                    ->nullable()
                    ->rules([new ValidVariables]),
                TextInput::make('command_launch_browser')
                    ->label('Default launch browser command')
                    ->helperText('The command to run to launch a browser for a specific workspace\'s local site.')
                    ->placeholder($settings->command_launch_browser ?? 'open "{{ ENV_APP_URL }}"')
                    ->nullable()
                    ->rules([new ValidVariables]),
                KeyValueEntry::make('variables')
                    ->label('Available variables')
                    ->keyLabel('Variable')
                    ->valueLabel('Example')
                    ->state([
                        ...collect(Variable::cases())->mapWithKeys(fn (Variable $var) => ['{{ '.$var->value.' }}' => $var->example()]),
                        '{{ ENV_ANY_KEY }}' => '(any key from .env prefixed by "ENV_")',
                    ]),
            ])
            ->action(function (array $data) {
                static::resultNotificationOperation(
                    callback: function () use ($data) {
                        $projectData = $this->projectData();
                        $projectData->command_launch_terminal = $data['command_launch_terminal'] ?? null;
                        $projectData->command_launch_ide = $data['command_launch_ide'] ?? null;
                        $projectData->command_launch_browser = $data['command_launch_browser'] ?? null;

                        app(ProjectsService::class)->updateProject($projectData);

                        $this->loadProjectData($this->projectData->uuid);
                        $this->resetTable();
                    },
                    successTitle: 'Launch commands updated',
                    failureBody: fn (Throwable $th) => $th->getMessage(),
                );
            });
    }

    public function table(Table $table): Table
    {
        $settings = app(SettingsService::class)->loadSettings();

        return $table
            ->records(fn () => $this->workspaces)
            ->columns([
                IconColumn::make('is_primary')
                    ->label('Primary')
                    ->alignCenter()
                    ->boolean(),
                TextColumn::make('branch')
                    ->grow(),
                TextColumn::make('status')
                    ->badge()
                    ->size(TextSize::Large)
                    ->color(fn ($state) => WorkspaceStatus::from($state)->getColor()),
            ])
            ->filters([
                // ...
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('launch_terminal')
                        ->label('Terminal')
                        ->hidden(empty($this->projectData->command_launch_terminal) && empty($settings->command_launch_terminal))
                        ->icon(Heroicon::ComputerDesktop)
                        ->action(function (array $record) {
                            $workspaceData = WorkspaceData::from($record);

                            static::resultNotificationOperation(
                                callback: function () use ($workspaceData) {
                                    app(LaunchService::class)->launchTerminal($this->projectData, $workspaceData);
                                },
                                successTitle: 'Terminal launched',
                                failureBody: fn (Throwable $th) => $th->getMessage(),
                            );
                        }),
                    Action::make('launch_ide')
                        ->label('IDE')
                        ->icon(Heroicon::CodeBracket)
                        ->hidden(empty($this->projectData->command_launch_ide) && empty($settings->command_launch_ide))
                        ->action(function (array $record) {
                            $workspaceData = WorkspaceData::from($record);

                            static::resultNotificationOperation(
                                callback: function () use ($workspaceData) {
                                    app(LaunchService::class)->launchIde($this->projectData, $workspaceData);
                                },
                                successTitle: 'IDE launched',
                                failureBody: fn (Throwable $th) => $th->getMessage(),
                            );
                        }),
                    Action::make('launch_browser')
                        ->label('Browser')
                        ->hidden(empty($this->projectData->command_launch_browser) && empty($settings->command_launch_browser))
                        ->icon(Heroicon::OutlinedGlobeAlt)
                        ->action(function (array $record) {
                            $workspaceData = WorkspaceData::from($record);

                            static::resultNotificationOperation(
                                callback: function () use ($workspaceData) {
                                    app(LaunchService::class)->launchBrowser($this->projectData, $workspaceData);
                                },
                                successTitle: 'Browser launched',
                                failureBody: fn (Throwable $th) => $th->getMessage(),
                            );
                        }),
                ])
                    ->button()
                    ->label('Launch')
                    ->color('info'),
                ActionGroup::make([
                    Action::make('override_status')
                        ->label('Override status')
                        ->modal()
                        ->modalWidth(Width::Small)
                        ->modalHeading('Override Status')
                        ->modalDescription('Override the status of the workspace.')
                        ->modalSubmitActionLabel('Override')
                        ->modalCancelActionLabel('Cancel')
                        ->modalFooterActionsAlignment(Alignment::End)
                        ->fillForm(fn (array $record) => [
                            'status' => $record['status'],
                        ])
                        ->schema([
                            Select::make('status')
                                ->selectablePlaceholder(false)
                                ->native(false)
                                ->options([
                                    WorkspaceStatus::READY->value => WorkspaceStatus::READY->getLabel(),
                                    WorkspaceStatus::SUSPENDED->value => WorkspaceStatus::SUSPENDED->getLabel(),
                                ]),
                        ])
                        ->action(function (array $record, array $data) {
                            static::resultNotificationOperation(
                                callback: function () use ($record, $data) {
                                    app(ProjectsService::class)->updateProjectWorkspaceStatus($record['path'], WorkspaceStatus::from($data['status']));

                                    $this->loadProjectData($this->projectData->uuid);
                                    $this->resetTable();
                                },
                                successTitle: 'Status overridden',
                                failureBody: fn (Throwable $th) => $th->getMessage(),
                            );
                        }),
                ]),
            ])
            ->toolbarActions([

            ])
            ->paginated(false);
    }
}
