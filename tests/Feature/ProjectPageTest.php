<?php

use App\Data\ProjectData;
use App\Data\SettingsData;
use App\Data\WorkflowData;
use App\Data\WorkspaceData;
use App\Enums\GitStatus;
use App\Enums\SessionKey;
use App\Enums\WorkspaceStatus;
use App\Events\ProjectDataUpdated;
use App\Exceptions\GitOperationFailed;
use App\Exceptions\ProjectNotFound;
use App\Filament\Pages\Project;
use App\Filament\Pages\WorkflowLog;
use App\Services\GitService;
use App\Services\LaunchService;
use App\Services\ProjectsService;
use App\Services\SettingsService;
use App\Services\WorkflowService;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Mockery\MockInterface;

beforeEach(function () {
    $this->uuid = '11111111-2222-3333-4444-555555555555';
    $this->projectPath = '/tmp/repo';
    $this->workspacePath = '/tmp/repo-feature';

    $this->project = componentProjectData(
        uuid: $this->uuid,
        path: $this->projectPath,
        ide: 'open "{{ WORKSPACE_DIR }}" -a phpstorm',
        browser: 'open "{{ ENV_APP_URL }}"',
        terminal: 'open "{{ WORKSPACE_DIR }}" -a iterm',
    );

    $this->workspace = componentWorkspaceData(path: $this->workspacePath);
    $this->step = componentStepData();
    $this->stepHash = $this->step->hash('0');
    $this->workflows = ['up' => componentWorkflowData(steps: [$this->step])];
});

describe('mount', function () {
    it('loads the project and lists its workspaces', function () {
        projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->assertOk()
            ->assertSet('loadedInvalidMessage', null)
            ->assertSee('feature');
    });

    it('records the load failure instead of throwing', function () {
        projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            loadProjectThrows: new ProjectNotFound($this->uuid),
        );

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->assertOk()
            ->assertSet('loadedInvalidMessage', "Project with UUID '{$this->uuid}' not found.");
    });

    it('shows a query string success as a persistent notification', function () {
        projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        Livewire::withQueryParams(['success' => 'Workflow [up] is valid.'])
            ->test(Project::class, ['uuid' => $this->uuid])
            ->assertOk()
            ->assertNotified(
                Notification::make()
                    ->success()
                    ->title('Workflow [up] is valid.')
                    ->icon(Heroicon::CheckCircle)
                    ->persistent()
            );
    });

    /**
     * The notification must be sent above mount()'s PROJECT_CREATED early return, which this covers by
     * leaving the session key unset.
     */
    it('shows a query string error as a persistent notification listing every problem', function () {
        projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        Livewire::withQueryParams([
            'error' => 'Workflow [up] is invalid',
            'body' => "{$this->workspacePath}/.laborforest/workflows/up.yaml\n• The steps field is required.",
        ])
            ->test(Project::class, ['uuid' => $this->uuid])
            ->assertOk()
            ->assertNotified(
                Notification::make()
                    ->danger()
                    ->title('Workflow [up] is invalid')
                    ->body(new HtmlString("{$this->workspacePath}/.laborforest/workflows/up.yaml<br />\n• The steps field is required."))
                    ->icon(Heroicon::XCircle)
                    ->persistent()
            );
    });

    it('shows nothing when no notification is on the query string', function () {
        projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->assertOk()
            ->assertNotNotified()
            ->assertNoJs();
    });

    it('clears the parameters from the address bar so a reload cannot repeat the notification', function () {
        projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        Livewire::withQueryParams(['success' => 'Workflow [up] is valid.'])
            ->test(Project::class, ['uuid' => $this->uuid])
            ->assertOk()
            ->assertJs(componentQueryStringClearingJs());
    });
});

describe('projectCreated action', function () {
    beforeEach(function () {
        $this->primaryWorkspace = componentWorkspaceData(
            path: $this->projectPath,
            isPrimary: true,
            branch: 'main',
            gitStatus: GitStatus::DIRTY,
        );
    });

    it('is mounted when the freshly created project has a dirty primary workspace', function () {
        projectPageServices(
            project: $this->project,
            workspaces: [$this->primaryWorkspace],
            workflows: $this->workflows,
        );

        session()->put(SessionKey::PROJECT_CREATED->value, $this->uuid);

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->assertOk()
            ->assertActionMounted('projectCreated');
    });

    it('is not mounted for a project that was not just created', function () {
        projectPageServices(
            project: $this->project,
            workspaces: [$this->primaryWorkspace],
            workflows: $this->workflows,
        );

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->assertOk()
            ->assertActionNotMounted('projectCreated');
    });

    it('is not mounted when the primary workspace is clean', function () {
        projectPageServices(
            project: $this->project,
            workspaces: [componentWorkspaceData(path: $this->projectPath, isPrimary: true, branch: 'main')],
            workflows: $this->workflows,
        );

        session()->put(SessionKey::PROJECT_CREATED->value, $this->uuid);

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->assertOk()
            ->assertActionNotMounted('projectCreated');
    });

    it('commits every change with the submitted message', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->primaryWorkspace],
            workflows: $this->workflows,
        );

        $services['git']->shouldReceive('commitAll')->once()->with($this->projectPath, 'initialize LaborForest');

        session()->put(SessionKey::PROJECT_CREATED->value, $this->uuid);

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->assertActionMounted('projectCreated')
            ->setActionData(['commit_message' => 'initialize LaborForest'])
            ->callMountedAction()
            ->assertNotified('Changes committed');
    });

    it('adds the base directory to the exclude file instead of committing', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->primaryWorkspace],
            workflows: $this->workflows,
        );

        $services['git']->shouldReceive('addToGitInfoExclude')->once()->with($this->projectPath, '/.laborforest/');
        $services['git']->shouldNotReceive('commitAll');

        session()->put(SessionKey::PROJECT_CREATED->value, $this->uuid);

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->assertActionMounted('projectCreated')
            ->mountAction('addToGitInfoExclude')
            ->callMountedAction()
            ->assertNotified('Added to .git/info/exclude')
            ->assertActionNotMounted('projectCreated');
    });

    it('reports a failed exclude and does not reload the project', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->primaryWorkspace],
            workflows: $this->workflows,
            loadProjectTimes: 1,
        );

        $services['git']
            ->shouldReceive('addToGitInfoExclude')
            ->andThrow(new GitOperationFailed('locate the git directory', 'fatal: not a git repository'));

        session()->put(SessionKey::PROJECT_CREATED->value, $this->uuid);

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->assertActionMounted('projectCreated')
            ->mountAction('addToGitInfoExclude')
            ->callMountedAction()
            ->assertNotified('Whoops! Something went wrong.');
    });

    it('reports a failed commit and does not reload the project', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->primaryWorkspace],
            workflows: $this->workflows,
            loadProjectTimes: 1,
        );

        $services['git']->shouldReceive('commitAll')->andThrow(new GitOperationFailed('commit', 'nothing to commit'));

        session()->put(SessionKey::PROJECT_CREATED->value, $this->uuid);

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->assertActionMounted('projectCreated')
            ->setActionData(['commit_message' => 'initialize LaborForest'])
            ->callMountedAction()
            ->assertNotified('Whoops! Something went wrong.');
    });
});

describe('addWorkspace action', function () {
    it('adds a workspace for an existing branch', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        $services['projects']
            ->shouldReceive('addProjectWorkspace')
            ->once()
            ->withArgs(fn (ProjectData $projectData, string $branch, ?string $baseBranch) => $projectData->uuid === $this->uuid
                && $branch === 'develop')
            ->andReturn($this->workspace);

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction('addWorkspace', [
                'new_or_existing' => 'existing',
                'existing_branch' => 'develop',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Workspace added');
    });

    it('reports a failed add and does not reload the project', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
            loadProjectTimes: 1,
        );

        $services['projects']
            ->shouldReceive('addProjectWorkspace')
            ->once()
            ->andThrow(new GitOperationFailed('add worktree', 'branch already checked out'));

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction('addWorkspace', [
                'new_or_existing' => 'existing',
                'existing_branch' => 'develop',
            ])
            ->assertNotified('Whoops! Something went wrong.');
    });
});

describe('remove action', function () {
    beforeEach(function () {
        $this->primaryWorkspace = componentWorkspaceData(
            path: $this->projectPath,
            isPrimary: true,
            branch: 'main',
        );
    });

    it('offers the force worktree checkbox when the project has a linked workspace', function () {
        projectPageServices(
            project: $this->project,
            workspaces: [$this->primaryWorkspace, $this->workspace],
            workflows: $this->workflows,
        );

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->mountAction('remove')
            ->assertSchemaComponentVisible('force_remove_worktrees');
    });

    it('hides the force worktree checkbox when only the primary workspace exists', function () {
        projectPageServices(
            project: $this->project,
            workspaces: [$this->primaryWorkspace],
            workflows: $this->workflows,
        );

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->mountAction('remove')
            ->assertSchemaComponentHidden('force_remove_worktrees')
            ->assertSchemaComponentVisible('remove_dir');
    });

    it('never forces removal while the checkbox is hidden', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->primaryWorkspace],
            workflows: $this->workflows,
        );

        $services['projects']->shouldReceive('removeProject')->once()->with($this->uuid, false, false);

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction('remove', ['force_remove_worktrees' => true])
            ->assertNotified('Project removed')
            ->assertRedirect('/');
    });

    it('removes the project and returns to the dashboard', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        $services['projects']->shouldReceive('removeProject')->once()->with($this->uuid, false, false);

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction('remove', [])
            ->assertNotified('Project removed')
            ->assertRedirect('/');
    });

    it('removes the .laborforest directory when the checkbox is ticked', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        $services['projects']->shouldReceive('removeProject')->once()->with($this->uuid, true, false);

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction('remove', ['remove_dir' => true])
            ->assertNotified('Project removed')
            ->assertRedirect('/');
    });

    it('force removes the worktrees when the checkbox is ticked', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        $services['projects']->shouldReceive('removeProject')->once()->with($this->uuid, false, true);

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction('remove', ['force_remove_worktrees' => true])
            ->assertNotified('Project removed')
            ->assertRedirect('/');
    });

    it('passes both removal options when both checkboxes are ticked', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        $services['projects']->shouldReceive('removeProject')->once()->with($this->uuid, true, true);

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction('remove', ['remove_dir' => true, 'force_remove_worktrees' => true])
            ->assertNotified('Project removed')
            ->assertRedirect('/');
    });

    it('reports a failed worktree removal and stays on the page', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        $services['projects']
            ->shouldReceive('removeProject')
            ->once()
            ->andThrow(new GitOperationFailed('remove worktree (forced)', 'contains modified or untracked files'));

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction('remove', ['force_remove_worktrees' => true])
            ->assertNotified('Whoops! Something went wrong.')
            ->assertNoRedirect();
    });

    it('reports a failed removal and stays on the page', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        $services['projects']->shouldReceive('removeProject')->once()->andThrow(new RuntimeException('projects.yaml is locked'));

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction('remove')
            ->assertNotified('Whoops! Something went wrong.')
            ->assertNoRedirect();
    });
});

describe('editLaunchCommands action', function () {
    it('saves all three launch commands onto the project', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        $services['projects']
            ->shouldReceive('updateProject')
            ->once()
            ->withArgs(fn (ProjectData $projectData) => $projectData->uuid === $this->uuid
                && $projectData->command_launch_terminal === 'open "{{ WORKSPACE_DIR }}" -a ghostty'
                && $projectData->command_launch_ide === 'open "{{ WORKSPACE_DIR }}" -a zed'
                && $projectData->command_launch_browser === 'open "{{ ENV_APP_URL }}" -a safari');

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction('editLaunchCommands', [
                'command_launch_terminal' => 'open "{{ WORKSPACE_DIR }}" -a ghostty',
                'command_launch_ide' => 'open "{{ WORKSPACE_DIR }}" -a zed',
                'command_launch_browser' => 'open "{{ ENV_APP_URL }}" -a safari',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Launch commands updated');
    });

    it('stores a cleared command as null so the global default applies again', function () {
        $services = projectPageServices(
            project: componentProjectData(
                uuid: $this->uuid,
                path: $this->projectPath,
                terminal: 'open "{{ WORKSPACE_DIR }}" -a ghostty',
            ),
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        $services['projects']
            ->shouldReceive('updateProject')
            ->once()
            ->withArgs(fn (ProjectData $projectData) => $projectData->command_launch_terminal === null
                && $projectData->command_launch_ide === null
                && $projectData->command_launch_browser === null);

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction('editLaunchCommands', [
                'command_launch_terminal' => '',
                'command_launch_ide' => '',
                'command_launch_browser' => '',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Launch commands updated');
    });

    it('reports a failed save and does not reload the project', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
            loadProjectTimes: 1,
        );

        $services['projects']
            ->shouldReceive('updateProject')
            ->once()
            ->andThrow(new RuntimeException('projects.yaml is not writable'));

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction('editLaunchCommands', [
                'command_launch_terminal' => 'open "{{ WORKSPACE_DIR }}" -a ghostty',
                'command_launch_ide' => null,
                'command_launch_browser' => null,
            ])
            ->assertNotified('Whoops! Something went wrong.');
    });
});

describe('launch record actions', function () {
    it('launches the terminal for the workspace', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        $services['launch']
            ->shouldReceive('launchTerminal')
            ->once()
            ->withArgs(fn (ProjectData $projectData, WorkspaceData $workspaceData) => $projectData->uuid === $this->uuid
                && $workspaceData->path === $this->workspacePath);

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction(TestAction::make('launch_terminal')->table('0'))
            ->assertNotified('Terminal launched');
    });

    it('launches the IDE for the workspace', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        $services['launch']->shouldReceive('launchIde')->once();

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction(TestAction::make('launch_ide')->table('0'))
            ->assertNotified('IDE launched');
    });

    it('launches the browser for the workspace', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        $services['launch']->shouldReceive('launchBrowser')->once();

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction(TestAction::make('launch_browser')->table('0'))
            ->assertNotified('Browser launched');
    });

    it('reports a failed terminal launch', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        $services['launch']->shouldReceive('launchTerminal')->once()->andThrow(new RuntimeException('no such application'));

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction(TestAction::make('launch_terminal')->table('0'))
            ->assertNotified('Whoops! Something went wrong.');
    });

    it('hides every launch action when neither the project nor the settings define a command', function () {
        projectPageServices(
            project: componentProjectData(uuid: $this->uuid, path: $this->projectPath),
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->assertActionHidden(TestAction::make('launch_terminal')->table('0'))
            ->assertActionHidden(TestAction::make('launch_ide')->table('0'))
            ->assertActionHidden(TestAction::make('launch_browser')->table('0'));
    });

    it('keeps the launch action visible for a cleared override the settings still cover', function () {
        projectPageServices(
            project: componentProjectData(uuid: $this->uuid, path: $this->projectPath, terminal: ''),
            workspaces: [$this->workspace],
            workflows: $this->workflows,
            settings: new SettingsData(command_launch_terminal: 'open "{{ WORKSPACE_DIR }}" -a iterm'),
        );

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->assertActionVisible(TestAction::make('launch_terminal')->table('0'));
    });
});

describe('create_example_workflows record action', function () {
    it('copies the selected example set into the workspace', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        $services['projects']
            ->shouldReceive('initializeWorkspaceStarterWorkflows')
            ->once()
            ->with($this->workspacePath, 'example-workflows/laravel');

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction(TestAction::make('create_example_workflows')->table('0'), [
                'example_path' => 'example-workflows/laravel',
            ])
            ->assertNotified(
                Notification::make()
                    ->success()
                    ->title('Example workflows created')
                    ->icon(Heroicon::CheckCircle)
            );
    });

    it('warns that the copied workflows made the git branch dirty', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [componentWorkspaceData($this->workspacePath, gitStatus: GitStatus::DIRTY)],
            workflows: $this->workflows,
        );

        $services['projects']
            ->shouldReceive('initializeWorkspaceStarterWorkflows')
            ->once()
            ->with($this->workspacePath, 'example-workflows/laravel');

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction(TestAction::make('create_example_workflows')->table('0'), [
                'example_path' => 'example-workflows/laravel',
            ])
            ->assertNotified(
                Notification::make()
                    ->success()
                    ->title('Example workflows created')
                    ->body('Git branch is now dirty!')
                    ->icon(Heroicon::CheckCircle)
            );
    });

    it('offers every bundled example set, labelled by its directory name', function () {
        projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
            exampleWorkflowPaths: ['example-workflows/bare', 'example-workflows/laravel', 'example-workflows/vite'],
        );

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->mountAction(TestAction::make('create_example_workflows')->table('0'))
            ->assertFormFieldExists('example_path', fn (Select $field) => $field->getOptions() === [
                'example-workflows/bare' => 'Bare',
                'example-workflows/laravel' => 'Laravel',
                'example-workflows/vite' => 'Vite',
            ] && ! $field->canSelectPlaceholder() && ! $field->isNative())
            ->assertActionDataSet(['example_path' => 'example-workflows/bare']);
    });

    it('is hidden once the workspace already has a workflow', function () {
        projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
            anyWorkflowExists: true,
        );

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->assertActionHidden(TestAction::make('create_example_workflows')->table('0'));
    });

    it('reports a failed copy and does not reload the project', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
            loadProjectTimes: 1,
        );

        $services['projects']
            ->shouldReceive('initializeWorkspaceStarterWorkflows')
            ->once()
            ->andThrow(new RuntimeException('unable to create .laborforest/workflows'));

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction(TestAction::make('create_example_workflows')->table('0'), [
                'example_path' => 'example-workflows/laravel',
            ])
            ->assertNotified('Whoops! Something went wrong.');
    });
});

describe('workflow record action', function () {
    beforeEach(function () {
        $this->logId = '20240101T000000Z_repo-feature_up';
    });

    it('dispatches the workflow with the checked step hashes', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        $services['workflows']->shouldReceive('loadSteps')->andReturn(collect([$this->step]));
        $services['workflows']
            ->shouldReceive('dispatchWorkflow')
            ->once()
            ->withArgs(fn (
                string $projectUuid,
                string $workspacePath,
                string $workflowName,
                array $stepHashes,
                ?string $parentLogId,
                int $timeoutSeconds,
            ) => $projectUuid === $this->uuid
                && $workspacePath === $this->workspacePath
                && $workflowName === 'up'
                && $stepHashes === [$this->stepHash]
                && $parentLogId === null
                && $timeoutSeconds === 600)
            ->andReturn($this->logId);

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction(
                TestAction::make('workflow_up')->table('0')->arguments(['watch' => false]),
                ['step_'.$this->stepHash => true],
            )
            ->assertNotified('Up workflow started')
            ->assertNoRedirect();
    });

    it('redirects to the run log when asked to watch the run', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        $services['workflows']->shouldReceive('loadSteps')->andReturn(collect([$this->step]));
        $services['workflows']->shouldReceive('dispatchWorkflow')->once()->andReturn($this->logId);

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction(
                TestAction::make('workflow_up')->table('0')->arguments(['watch' => true]),
                ['step_'.$this->stepHash => true],
            )
            ->assertNotified('Up workflow started')
            ->assertRedirect(WorkflowLog::getUrl([
                'uuid' => $this->uuid,
                'slug' => 'repo-feature',
                'id' => $this->logId,
            ]));
    });

    it('reports a failed dispatch and does not redirect', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
            loadProjectTimes: 1,
        );

        $services['workflows']->shouldReceive('loadSteps')->andReturn(collect([$this->step]));
        $services['workflows']
            ->shouldReceive('dispatchWorkflow')
            ->once()
            ->andThrow(new RuntimeException('workflow file is invalid'));

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction(
                TestAction::make('workflow_up')->table('0')->arguments(['watch' => true]),
                ['step_'.$this->stepHash => true],
            )
            ->assertNotified('Whoops! Something went wrong.')
            ->assertNoRedirect();
    });

    it('is disabled while the workspace is working', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [componentWorkspaceData(path: $this->workspacePath, status: WorkspaceStatus::WORKING)],
            workflows: $this->workflows,
        );

        $services['workflows']->shouldReceive('loadSteps')->andReturn(collect([$this->step]));

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->assertActionDisabled(TestAction::make('workflow_up')->table('0'));
    });
});

describe('override_status record action', function () {
    it('overrides the workspace status', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        $services['projects']
            ->shouldReceive('updateProjectWorkspaceStatus')
            ->once()
            ->with($this->workspacePath, WorkspaceStatus::SUSPENDED);

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction(
                TestAction::make('override_status')->table('0'),
                ['status' => WorkspaceStatus::SUSPENDED->value],
            )
            ->assertNotified('Status updated');
    });

    it('reports a failed override and does not reload the project', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
            loadProjectTimes: 1,
        );

        $services['projects']
            ->shouldReceive('updateProjectWorkspaceStatus')
            ->once()
            ->andThrow(new RuntimeException('status.yaml is not writable'));

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction(
                TestAction::make('override_status')->table('0'),
                ['status' => WorkspaceStatus::SUSPENDED->value],
            )
            ->assertNotified('Whoops! Something went wrong.');
    });
});

describe('workspace remove record action', function () {
    beforeEach(function () {
        $this->suspendedWorkspace = componentWorkspaceData(
            path: $this->workspacePath,
            status: WorkspaceStatus::SUSPENDED,
        );
    });

    it('removes the worktree of a suspended workspace', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->suspendedWorkspace],
            workflows: $this->workflows,
        );

        $services['git']
            ->shouldReceive('removeWorktree')
            ->once()
            ->withArgs(fn (
                string $mainWorktreePath,
                string $worktreePath,
                string $branch,
                bool $force,
                bool $deleteBranch,
                bool $forceDeleteBranch,
            ) => $mainWorktreePath === $this->projectPath
                && $worktreePath === $this->workspacePath
                && $branch === 'feature'
                && $force === true
                && $deleteBranch === false
                && $forceDeleteBranch === false);

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction(
                TestAction::make('remove')->table('0'),
                ['force_delete_worktree' => true],
            )
            ->assertNotified('Workspace removed');
    });

    it('is hidden for a ready workspace', function () {
        projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
        );

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->assertActionHidden(TestAction::make('remove')->table('0'));
    });

    it('reports a failed worktree removal and does not reload the project', function () {
        $services = projectPageServices(
            project: $this->project,
            workspaces: [$this->suspendedWorkspace],
            workflows: $this->workflows,
            loadProjectTimes: 1,
        );

        $services['git']
            ->shouldReceive('removeWorktree')
            ->once()
            ->andThrow(new GitOperationFailed('remove worktree', 'worktree is dirty'));

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->callAction(TestAction::make('remove')->table('0'), [])
            ->assertNotified('Whoops! Something went wrong.');
    });
});

describe('onProjectDataUpdated listener', function () {
    it('reloads the page when the broadcast is for this project', function () {
        projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
            loadProjectTimes: 2,
        );

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->dispatch('native:'.ProjectDataUpdated::class, projectUuid: $this->uuid)
            ->assertOk();
    });

    it('ignores a broadcast for a different project', function () {
        projectPageServices(
            project: $this->project,
            workspaces: [$this->workspace],
            workflows: $this->workflows,
            loadProjectTimes: 1,
        );

        Livewire::test(Project::class, ['uuid' => $this->uuid])
            ->dispatch('native:'.ProjectDataUpdated::class, projectUuid: 'a-different-uuid')
            ->assertOk();
    });
});

/**
 * Double every service the Project page reaches while mounting, rendering and building its actions.
 *
 * Only the load path is stubbed here; each test layers the expectation for the one write method its
 * action calls onto the returned mocks.
 *
 * @param  array<int, WorkspaceData>  $workspaces
 * @param  array<string, WorkflowData>  $workflows
 * @param  array<int, string>  $branches
 * @param  array<int, string>  $exampleWorkflowPaths
 * @return array{projects: MockInterface, workflows: MockInterface, settings: MockInterface, git: MockInterface, launch: MockInterface}
 */
function projectPageServices(
    ProjectData $project,
    array $workspaces,
    array $workflows = [],
    ?SettingsData $settings = null,
    ?Throwable $loadProjectThrows = null,
    bool $anyWorkflowExists = false,
    array $branches = ['develop', 'main'],
    string $currentBranch = 'main',
    ?int $loadProjectTimes = null,
    array $exampleWorkflowPaths = ['example-workflows/bare', 'example-workflows/laravel'],
): array {
    $settings ??= new SettingsData;

    $projectsMock = projectPageMock(ProjectsService::class, function (MockInterface $mock) use (
        $project,
        $workspaces,
        $branches,
        $anyWorkflowExists,
        $loadProjectThrows,
        $loadProjectTimes,
        $exampleWorkflowPaths,
    ) {
        $loadProject = $mock->shouldReceive('loadProject');

        if ($loadProjectTimes !== null) {
            $loadProject->times($loadProjectTimes);
        }

        if ($loadProjectThrows !== null) {
            $loadProject->andThrow($loadProjectThrows);
        } else {
            $loadProject->andReturn($project);
        }

        $mock->shouldReceive('loadProjectWorkspaces')->andReturn(collect($workspaces));
        $mock->shouldReceive('listProjectLocalBranches')->andReturn(collect($branches));
        $mock->shouldReceive('doesAnyProjectWorkspaceWorkflowExist')->andReturn($anyWorkflowExists);
        $mock->shouldReceive('listExampleWorkflowPaths')->andReturn(collect($exampleWorkflowPaths));
    });

    $workflowsMock = projectPageMock(WorkflowService::class, function (MockInterface $mock) use ($workflows) {
        $mock->shouldReceive('loadWorkflows')->andReturn(collect($workflows));
    });

    $settingsMock = projectPageMock(SettingsService::class, function (MockInterface $mock) use ($settings) {
        $mock->shouldReceive('loadSettings')->andReturn($settings);
    });

    $gitMock = projectPageMock(GitService::class, function (MockInterface $mock) use ($currentBranch) {
        $mock->shouldReceive('currentBranch')->andReturn($currentBranch);
        $mock->shouldReceive('status')->andReturn(collect());
    });

    $launchMock = projectPageMock(LaunchService::class);

    return [
        'projects' => $projectsMock,
        'workflows' => $workflowsMock,
        'settings' => $settingsMock,
        'git' => $gitMock,
        'launch' => $launchMock,
    ];
}

/**
 * Register a Mockery double for a service in the container, the way $this->mock() would.
 *
 * @param  class-string  $abstract
 */
function projectPageMock(string $abstract, ?Closure $configure = null): MockInterface
{
    $mock = Mockery::mock($abstract);

    if ($configure !== null) {
        $configure($mock);
    }

    app()->instance($abstract, $mock);

    return $mock;
}
