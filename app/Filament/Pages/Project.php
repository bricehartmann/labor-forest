<?php

namespace App\Filament\Pages;

use App\Concerns\Filament\Pages\HasResultNotificationOperations;
use App\Data\GitStatusEntryData;
use App\Data\ProjectData;
use App\Data\WorkflowData;
use App\Data\WorkflowStepData;
use App\Data\WorkspaceData;
use App\Enums\Directory;
use App\Enums\GitStatus;
use App\Enums\Regex;
use App\Enums\SessionKey;
use App\Enums\Variable;
use App\Enums\WorkspaceStatus;
use App\Exceptions\GitOperationFailed;
use App\Exceptions\InvalidProjectsFile;
use App\Rules\ValidVariables;
use App\Services\GitService;
use App\Services\LaunchService;
use App\Services\ProjectsService;
use App\Services\SettingsService;
use App\Services\WorkflowService;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
use Illuminate\Support\Str;
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
    public array $workflows = [];

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

        if (session()->get(SessionKey::PROJECT_CREATED->value) !== $uuid) {
            return;
        }

        session()->forget(SessionKey::PROJECT_CREATED->value);

        if ($this->loadedInvalidMessage === null && $this->isPrimaryWorkspaceDirty()) {
            $this->mountAction('projectCreated');
        }
    }

    protected function isPrimaryWorkspaceDirty(): bool
    {
        $primaryWorkspace = collect($this->workspaces)->firstWhere('is_primary', true);

        return ($primaryWorkspace['git_status'] ?? null) === GitStatus::DIRTY->value;
    }

    protected function reloadData(): void
    {
        $this->loadProjectData($this->projectData->uuid);
        $this->resetTable();
    }

    protected function loadProjectData(string $uuid): void
    {
        unset($this->projectData);

        $projectService = app(ProjectsService::class);

        try {
            $this->project = $projectService->loadProject($uuid)->toArray();
            $this->workspaces = $projectService->loadProjectWorkspaces($this->projectData->path)->toArray();
            $this->workflows = $this->loadWorkspaceWorkflows();
        } catch (InvalidProjectsFile $e) {
            $this->loadedInvalidMessage = $e->messagesAsString();
        } catch (Exception $e) {
            $this->loadedInvalidMessage = $e->getMessage();
        }
    }

    /**
     * @return array<string, array<string, int>> workspace path => (workflow name => sort order)
     */
    protected function loadWorkspaceWorkflows(): array
    {
        $workflowService = app(WorkflowService::class);

        return collect($this->workspaces)
            ->mapWithKeys(fn (array $workspace) => [
                $workspace['path'] => $workflowService->loadWorkflows($workspace['path'])
                    ->map(fn (WorkflowData $workflowData) => $workflowData->sort_order)
                    ->all(),
            ])
            ->all();
    }

    /**
     * The union of every workspace's workflow names, ordered by sort order then name.
     *
     * @return array<int, string>
     */
    protected function workflowNames(): array
    {
        $sortOrders = collect($this->workflows)->reduce(function (array $carry, array $workflows) {
            foreach ($workflows as $name => $sortOrder) {
                $carry[$name] = min($carry[$name] ?? $sortOrder, $sortOrder);
            }

            return $carry;
        }, []);

        return collect($sortOrders)
            ->sortBy(fn (int $sortOrder, string $name) => [$sortOrder, $name])
            ->keys()
            ->all();
    }

    public static function getSlug($panel = null): string
    {
        return '/projects/{uuid}';
    }

    public function getHeading(): string
    {
        return $this->projectData->dirName();
    }

    public function projectCreatedAction(): Action
    {
        return Action::make('projectCreated')
            ->modal()
            ->closeModalByClickingAway(false)
            ->closeModalByEscaping(false)
            ->modalCloseButton(false)
            ->modalWidth(Width::Medium)
            ->modalIcon(Heroicon::OutlinedExclamationTriangle)
            ->modalHeading('Repository is now dirty')
            ->modalDescription(new HtmlString('The <code class="text-red-600">'.Directory::BASE->value.'</code> directory has been created inside this project, so the repository now has uncommitted changes.<br/><br/>You can commit them now or handle them yourself later.'))
            ->modalSubmitActionLabel('Commit all changes')
            ->modalCancelActionLabel('Continue without committing')
            ->modalFooterActionsAlignment(Alignment::End)
            ->fillForm(fn () => [
                'commit_message' => 'initialize LaborForest',
            ])
            ->schema([
                TextEntry::make('dirty_files')
                    ->label('Untracked or modified files')
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->state(fn () => $this->dirtyFileDescriptions()),
                TextInput::make('commit_message')
                    ->label('Commit message')
                    ->required(),
            ])
            ->action(function (array $data) {
                static::resultNotificationOperation(
                    callback: function () use ($data) {
                        app(GitService::class)->commitAll($this->projectData->path, $data['commit_message']);

                        $this->reloadData();
                    },
                    successTitle: 'Changes committed',
                    failureBody: fn (Throwable $th) => $th->getMessage(),
                );
            });
    }

    /**
     * @return array<int, string>
     *
     * @throws GitOperationFailed
     */
    protected function dirtyFileDescriptions(): array
    {
        return rescue(fn () => app(GitService::class)
            ->status($this->projectData->path)
            ->map(fn (GitStatusEntryData $entry) => new HtmlString('<code class="text-red-600">'.$entry->path.'</code> ('.$entry->label().')'))
            ->all(), []);
    }

    public function addWorkspaceAction(): Action
    {
        return Action::make('addWorkspace')
            ->label('Add workspace')
            ->button()
            ->color('success')
            ->modal()
            ->modalWidth(Width::Medium)
            ->modalHeading('Add Workspace')
            ->modalDescription('Add a new workspace using a new or existing branch.')
            ->modalSubmitActionLabel('Add')
            ->modalCancelActionLabel('Cancel')
            ->modalFooterActionsAlignment(Alignment::End)
            ->fillForm(fn () => [
                'new_or_existing' => null,
                'existing_branch' => null,
                'new_branch' => null,
                'base_branch' => rescue(fn () => app(GitService::class)->currentBranch($this->projectData->path)),
            ])
            ->schema([
                Radio::make('new_or_existing')
                    ->live()
                    ->label('Create workspace for...')
                    ->options([
                        'new' => 'new branch',
                        'existing' => 'existing branch',
                    ])
                    ->inline()
                    ->required(),
                Select::make('existing_branch')
                    ->visible(fn (Get $get) => $get('new_or_existing') === 'existing')
                    ->label('Branch')
                    ->options(fn () => $this->branchOptions(onlyBranchesWithoutExistingWorkspace: true))
                    ->native(false)
                    ->searchable()
                    ->required(),
                TextInput::make('new_branch')
                    ->visible(fn (Get $get) => $get('new_or_existing') === 'new')
                    ->label('New branch name')
                    ->placeholder('feature/something-awesome')
                    ->required()
                    ->notIn($this->branchOptions(onlyBranchesWithoutExistingWorkspace: false))
                    ->regex(Regex::GIT_BRANCH_NAME->value),
                Select::make('base_branch')
                    ->visible(fn (Get $get) => $get('new_or_existing') === 'new')
                    ->label('Base branch for new branch')
                    ->options(fn () => $this->branchOptions(onlyBranchesWithoutExistingWorkspace: false))
                    ->native(false)
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data) {
                static::resultNotificationOperation(
                    callback: function () use ($data) {
                        app(ProjectsService::class)->addProjectWorkspace(
                            projectData: $this->projectData,
                            branch: $data['new_or_existing'] === 'new'
                                ? $data['new_branch']
                                : $data['existing_branch'],
                            baseBranch: $data['base_branch'] ?? null,
                        );

                        $this->reloadData();
                    },
                    successTitle: 'Workspace added',
                    failureBody: fn (Throwable $th) => $th->getMessage(),
                );
            });
    }

    /**
     * @return array<string, string>
     */
    protected function branchOptions(bool $onlyBranchesWithoutExistingWorkspace): array
    {
        return rescue(fn () => app(ProjectsService::class)
            ->listProjectLocalBranches($this->projectData->path, $onlyBranchesWithoutExistingWorkspace)
            ->mapWithKeys(fn (string $branch) => [$branch => $branch])
            ->all(), []);
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
            ->color('info')
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
                    ->label('Launch IDE command')
                    ->helperText('The command to run to launch a workspace directory in an IDE.')
                    ->placeholder($settings->command_launch_ide ?? 'open "{{ WORKSPACE_DIR }}" -a phpstorm')
                    ->nullable()
                    ->rules([new ValidVariables]),
                TextInput::make('command_launch_browser')
                    ->label('Launch browser command')
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

                        $this->reloadData();
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
                    ->boolean()
                    ->falseColor('gray'),
                TextColumn::make('branch')
                    ->grow(),
                TextColumn::make('status')
                    ->badge()
                    ->size(TextSize::Large)
                    ->color(fn ($state) => WorkspaceStatus::from($state)->getColor()),
                TextColumn::make('git_status')
                    ->label('Git')
                    ->badge()
                    ->size(TextSize::Large)
                    ->color(fn ($state) => GitStatus::from($state)->getColor()),
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
                    ->color('primary'),
                ActionGroup::make([
                    Action::make('create_starter_workflows')
                        ->hidden(fn (array $record) => app(ProjectsService::class)->doesAnyProjectWorkspaceWorkflowExist($record['path']))
                        ->label('Create starter workflows')
                        ->icon(Heroicon::SquaresPlus)
                        ->action(function (array $record) {
                            static::resultNotificationOperation(
                                callback: function () use ($record) {
                                    app(ProjectsService::class)->initializeWorkspaceStarterWorkflows($record['path']);

                                    $this->reloadData();
                                },
                                successTitle: 'Workflows created: up & down',
                                failureBody: fn (Throwable $th) => $th->getMessage(),
                            );
                        }),
                    ...collect($this->workflowNames())
                        ->map(fn (string $name) => Action::make('workflow_'.$name)
                            ->label(Str::headline($name))
                            ->icon(Heroicon::Play)
                            ->hidden(fn (array $record) => ! array_key_exists($name, $this->workflows[$record['path']] ?? []))
                            ->modal()
                            ->modalHeading('Run '.Str::headline($name).' Workflow')
                            ->modalDescription('Select which steps in the workflow you would like to run.')
                            ->modalSubmitActionLabel('Run')
                            ->modalFooterActionsAlignment(Alignment::End)
                            ->modalWidth(Width::Large)
                            ->schema(fn (array $record) => [
                                ...collect(app(WorkflowService::class)->loadSteps($record['path'], $name))
                                    ->map(fn (WorkflowStepData $step, int $index) => Checkbox::make('step_'.$index)
                                        ->label($step->name)
                                        ->default(true)
                                    ),
                            ])
                            ->action(function () use ($name) {
                                // TODO: execute the workflow once a runner service exists
                                Notification::make()
                                    ->warning()
                                    ->title('Workflow runner not implemented')
                                    ->body(Str::headline($name).' cannot run yet.')
                                    ->send();
                            }))
                        ->all(),
                ])
                    ->button()
                    ->label('Workflows')
                    ->color('success'),
                ActionGroup::make([
                    Action::make('override_status')
                        ->label('Override status')
                        ->icon(Heroicon::CheckCircle)
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

                                    $this->reloadData();
                                },
                                successTitle: 'Status updated',
                                failureBody: fn (Throwable $th) => $th->getMessage(),
                            );
                        }),
                    Action::make('remove')
                        ->hidden(fn ($record) => $record['is_primary'])
                        ->label('Remove')
                        ->icon(Heroicon::Trash)
                        ->modal()
                        ->modalWidth(Width::Small)
                        ->modalHeading('Remove Workspace')
                        ->modalDescription('Select how you want to remove this workspace.')
                        ->modalSubmitActionLabel('Remove')
                        ->modalCancelActionLabel('Cancel')
                        ->modalFooterActionsAlignment(Alignment::End)
                        ->schema([
                            Checkbox::make('force_delete_worktree')
                                ->label('Force worktree removal'),
                            Checkbox::make('delete_branch')
                                ->label('Delete branch')
                                ->live()
                                ->afterStateUpdated(function (Set $set, $state) {
                                    if (! $state) {
                                        $set('force_delete_branch', false);
                                    }
                                }),
                            Checkbox::make('force_delete_branch')
                                ->label('Force delete branch')
                                ->disabled(fn (Get $get) => ! $get('delete_branch')),
                        ])
                        ->action(function (array $record, array $data) {
                            static::resultNotificationOperation(
                                callback: function () use ($record, $data) {
                                    $workspaceData = WorkspaceData::from($record);

                                    app(GitService::class)->removeWorktree(
                                        mainWorktreePath: $this->projectData->path,
                                        worktreePath: $workspaceData->path,
                                        branch: $workspaceData->branch,
                                        force: $data['force_delete_worktree'] ?? false,
                                        deleteBranch: $data['delete_branch'] ?? false,
                                        forceDeleteBranch: $data['force_delete_branch'] ?? false,
                                    );

                                    $this->loadProjectData($this->projectData->uuid);
                                    $this->resetTable();
                                },
                                successTitle: 'Workspace removed',
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
