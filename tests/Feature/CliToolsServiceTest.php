<?php

use App\Data\ProjectData;
use App\Data\SettingsData;
use App\Enums\WorkspaceStatus;
use App\Exceptions\InvalidSettingsFile;
use App\Exceptions\InvalidWorkflowFile;
use App\Exceptions\ProjectDirectoryNotGitRepository;
use App\Exceptions\WorkflowNotRunnable;
use App\Exceptions\WorkspaceNotFound;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Project;
use App\Filament\Pages\WorkflowLog;
use App\Services\CliToolsService;
use App\Services\ProjectsService;
use App\Services\SettingsService;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Symfony\Component\Yaml\Yaml;

use function Pest\Laravel\mock;

beforeEach(function () {
    Storage::fake('user_home');

    $this->uuid = '11111111-1111-1111-1111-111111111111';
    $this->workspacePath = '/tmp/repo-feature';
    $this->workflowPath = '/tmp/repo-feature/.laborforest/workflows/up.yaml';

    /**
     * The paths File::isDirectory() and File::isFile() report as existing. Nothing is ever created
     * on disk; each test declares what the service should find.
     *
     * @var array<int, string>
     */
    $this->existingPaths = [$this->workspacePath, $this->workflowPath];

    /**
     * Every path handed to File::isFile(), so the resolved workflow path can be asserted.
     *
     * @var array<int, string>
     */
    $this->checkedFilePaths = [];

    /**
     * Only the fixture paths are answered from the declared set. Everything else — Laravel's own
     * translation files, loaded when a validation message is rendered — still has to resolve.
     */
    File::partialMock()
        ->shouldReceive('isDirectory')
        ->andReturnUsing(fn (string $path) => str_starts_with($path, '/tmp/')
            ? in_array($path, $this->existingPaths)
            : is_dir($path))
        ->shouldReceive('isFile')
        ->andReturnUsing(function (string $path) {
            if (! str_starts_with($path, '/tmp/')) {
                return is_file($path);
            }

            $this->checkedFilePaths[] = $path;

            return in_array($path, $this->existingPaths);
        });
});

describe('add-project', function () {
    it('adds the project and returns its page', function () {
        cliToolsWritePending(['command' => 'add-project', 'path' => $this->workspacePath]);

        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('addProject')
                ->once()
                ->with($this->workspacePath)
                ->andReturn(componentProjectData($this->uuid, $this->workspacePath));
        });

        expect(cliToolsRun())->toBe(Project::getUrl(['uuid' => $this->uuid]));
    });

    it('returns the dashboard when the path is not a directory', function () {
        cliToolsWritePending(['command' => 'add-project', 'path' => '/tmp/nope']);

        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('addProject');
        });

        expect(cliToolsRun())->toBe(cliToolsDashboardUrl('Path does not exist.'));
    });

    it('reports the failure when the service throws', function () {
        cliToolsWritePending(['command' => 'add-project', 'path' => $this->workspacePath]);

        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('addProject')
                ->once()
                ->andThrow(new ProjectDirectoryNotGitRepository($this->workspacePath));
        });

        expect(cliToolsRun())
            ->toBe(cliToolsDashboardUrl("Project with directory '{$this->workspacePath}' is not a git repository."));
    });
});

describe('run-workflow', function () {
    it('dispatches the workflow and returns its log', function () {
        $steps = [componentStepData(), componentStepData(name: 'Migrate', run: 'php artisan migrate')];
        $expectedHashes = [$steps[0]->hash('0'), $steps[1]->hash('1')];

        cliToolsWritePendingWorkflow($this->workspacePath, 'up');
        cliToolsMockProjectsService($this->workspacePath, componentProjectData($this->uuid, '/tmp/repo'));

        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')
                ->once()
                ->andReturn(new SettingsData(workflow_timeout_seconds: 45));
        });

        $this->mock(WorkflowService::class, function (MockInterface $mock) use ($steps, $expectedHashes) {
            $mock->shouldReceive('loadSteps')
                ->once()
                ->with($this->workspacePath, 'up')
                ->andReturn(collect($steps));

            $mock->shouldReceive('dispatchWorkflow')
                ->once()
                ->with($this->uuid, $this->workspacePath, 'up', $expectedHashes, null, 45)
                ->andReturn('20240101T000000Z_repo-feature_up');
        });

        expect(cliToolsRun())->toBe(WorkflowLog::getUrl([
            'uuid' => $this->uuid,
            'slug' => 'repo-feature',
            'id' => '20240101T000000Z_repo-feature_up',
        ]));
    });

    it('resolves the workflow file inside the workspace', function () {
        cliToolsWritePendingWorkflow($this->workspacePath, 'up');
        cliToolsMockProjectsService($this->workspacePath, componentProjectData($this->uuid, '/tmp/repo'));

        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')->andReturn(new SettingsData);
        });

        $this->mock(WorkflowService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSteps')->andReturn(collect([componentStepData()]));
            $mock->shouldReceive('dispatchWorkflow')->andReturn('20240101T000000Z_repo-feature_up');
        });

        cliToolsRun();

        expect($this->checkedFilePaths)->toBe([$this->workflowPath]);
    });

    it('returns the dashboard when the path is not a directory', function () {
        cliToolsWritePendingWorkflow('/tmp/nope', 'up');
        cliToolsExpectNoDispatch();

        expect(cliToolsRun())->toBe(cliToolsDashboardUrl('Path does not exist.'));
    });

    it('returns the dashboard when no workflow is given', function () {
        cliToolsWritePending(['command' => 'run-workflow', 'path' => $this->workspacePath]);
        cliToolsExpectNoDispatch();

        expect(cliToolsRun())->toBe(cliToolsDashboardUrl('Workflow does not exist.'));
    });

    it('returns the dashboard when the workflow file does not exist', function () {
        cliToolsWritePendingWorkflow($this->workspacePath, 'down');
        cliToolsExpectNoDispatch();

        expect(cliToolsRun())->toBe(cliToolsDashboardUrl('Workflow does not exist.'))
            ->and($this->checkedFilePaths)->toBe(['/tmp/repo-feature/.laborforest/workflows/down.yaml']);
    });

    it('reports the failure when the workspace is not found', function () {
        cliToolsWritePendingWorkflow($this->workspacePath, 'up');

        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjectWorkspace')
                ->once()
                ->andThrow(new WorkspaceNotFound($this->workspacePath));
            $mock->shouldNotReceive('loadProjectFromWorkspace');
        });

        cliToolsExpectNoDispatch();

        expect(cliToolsRun())
            ->toBe(cliToolsDashboardUrl("Workspace at path '{$this->workspacePath}' not found."));
    });

    it('reports the failure when the workspace belongs to no registered project', function () {
        cliToolsWritePendingWorkflow($this->workspacePath, 'up');
        cliToolsMockProjectsService($this->workspacePath, null);
        cliToolsExpectNoDispatch();

        expect(cliToolsRun())->toBe(cliToolsDashboardUrl('Project does not exist.'));
    });

    it('reports the failure when the settings file is invalid', function () {
        cliToolsWritePendingWorkflow($this->workspacePath, 'up');
        cliToolsMockProjectsService($this->workspacePath, componentProjectData($this->uuid, '/tmp/repo'));

        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')
                ->once()
                ->andThrow(InvalidSettingsFile::notAMapping('.laborforest/settings.yaml', 'string'));
        });

        cliToolsExpectNoDispatch();

        expect(cliToolsRun())
            ->toBe(cliToolsDashboardUrl('The settings file [.laborforest/settings.yaml] is invalid: Expected a mapping, found string.'));
    });

    it('reports the failure when the workflow file is invalid', function () {
        cliToolsWritePendingWorkflow($this->workspacePath, 'up');
        cliToolsMockProjectsService($this->workspacePath, componentProjectData($this->uuid, '/tmp/repo'));

        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')->andReturn(new SettingsData);
        });

        $this->mock(WorkflowService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSteps')
                ->once()
                ->andThrow(InvalidWorkflowFile::withProblems($this->workflowPath, ['The steps field is required.']));
            $mock->shouldNotReceive('dispatchWorkflow');
        });

        expect(cliToolsRun())
            ->toBe(cliToolsDashboardUrl("The workflow file [{$this->workflowPath}] is invalid: The steps field is required."));
    });

    it('reports the failure when the workspace is not in the status the workflow requires', function () {
        cliToolsWritePendingWorkflow($this->workspacePath, 'up');
        cliToolsMockProjectsService($this->workspacePath, componentProjectData($this->uuid, '/tmp/repo'));

        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')->andReturn(new SettingsData);
        });

        $this->mock(WorkflowService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSteps')->andReturn(collect([componentStepData()]));
            $mock->shouldReceive('dispatchWorkflow')
                ->once()
                ->andThrow(new WorkflowNotRunnable('up', WorkspaceStatus::READY, WorkspaceStatus::SUSPENDED));
        });

        expect(cliToolsRun())
            ->toBe(cliToolsDashboardUrl('Workflow [up] requires the workspace to be suspended, but it is ready.'));
    });

    it('reports the failure when dispatching throws', function () {
        cliToolsWritePendingWorkflow($this->workspacePath, 'up');
        cliToolsMockProjectsService($this->workspacePath, componentProjectData($this->uuid, '/tmp/repo'));

        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')->andReturn(new SettingsData);
        });

        $this->mock(WorkflowService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSteps')->andReturn(collect([componentStepData()]));
            $mock->shouldReceive('dispatchWorkflow')
                ->once()
                ->andThrow(new WorkspaceNotFound($this->workspacePath));
        });

        expect(cliToolsRun())
            ->toBe(cliToolsDashboardUrl("Workspace at path '{$this->workspacePath}' not found."));
    });
});

describe('the pending file', function () {
    it('returns null when there is no pending file', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('addProject');
        });

        cliToolsExpectNoDispatch();

        expect(cliToolsRun())->toBeNull();
    });

    it('is removed once the command succeeds', function () {
        cliToolsWritePending(['command' => 'add-project', 'path' => $this->workspacePath]);

        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('addProject')
                ->andReturn(componentProjectData($this->uuid, $this->workspacePath));
        });

        cliToolsRun();

        expect(cliToolsPendingExists())->toBeFalse();
    });

    it('is removed even when the command fails', function () {
        cliToolsWritePending(['command' => 'add-project', 'path' => $this->workspacePath]);

        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('addProject')
                ->andThrow(new ProjectDirectoryNotGitRepository($this->workspacePath));
        });

        cliToolsRun();

        expect(cliToolsPendingExists())->toBeFalse();
    });

    it('is discarded when it cannot be parsed', function () {
        Storage::disk('user_home')->put('.laborforest/pending.yaml', "command: add-project\n\tpath: /tmp/repo-feature");

        expect(cliToolsRun())->toBeNull()
            ->and(cliToolsPendingExists())->toBeFalse();
    });

    it('is discarded when it is not a mapping', function () {
        Storage::disk('user_home')->put('.laborforest/pending.yaml', 'add-project');

        expect(cliToolsRun())->toBeNull()
            ->and(cliToolsPendingExists())->toBeFalse();
    });

    it('is discarded when the command is not one the app knows', function () {
        cliToolsWritePending(['command' => 'delete-everything', 'path' => $this->workspacePath]);

        expect(cliToolsRun())->toBeNull()
            ->and(cliToolsPendingExists())->toBeFalse();
    });

    it('is discarded when the path is missing', function () {
        cliToolsWritePending(['command' => 'add-project']);

        expect(cliToolsRun())->toBeNull()
            ->and(cliToolsPendingExists())->toBeFalse();
    });

    it('only runs once, so a deeplink landing after the boot drain finds nothing', function () {
        cliToolsWritePending(['command' => 'add-project', 'path' => $this->workspacePath]);

        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('addProject')
                ->once()
                ->andReturn(componentProjectData($this->uuid, $this->workspacePath));
        });

        expect(cliToolsRun())->toBe(Project::getUrl(['uuid' => $this->uuid]))
            ->and(cliToolsRun())->toBeNull();
    });
});

/**
 * Leave a request behind exactly as the `lf` script does.
 *
 * @param  array<string, string>  $data
 */
function cliToolsWritePending(array $data): void
{
    Storage::disk('user_home')->put('.laborforest/pending.yaml', Yaml::dump($data));
}

/**
 * A pending run-workflow request.
 */
function cliToolsWritePendingWorkflow(string $path, string $workflow): void
{
    cliToolsWritePending(['command' => 'run-workflow', 'path' => $path, 'workflow' => $workflow]);
}

/**
 * The page the pending request resolves to, if any.
 */
function cliToolsRun(): ?string
{
    return app(CliToolsService::class)->runPendingCommand();
}

function cliToolsPendingExists(): bool
{
    return Storage::disk('user_home')->exists('.laborforest/pending.yaml');
}

/**
 * The dashboard URL the service builds for a failure.
 */
function cliToolsDashboardUrl(string $error): string
{
    return Dashboard::getUrl(['error' => $error]);
}

/**
 * Mock the workspace and project lookups the happy path performs.
 *
 * $projectData is the project the workspace resolves to; passing null covers a workspace whose
 * primary worktree is not a registered project, which loadProjectFromWorkspace() reports as null.
 */
function cliToolsMockProjectsService(string $workspacePath, ?ProjectData $projectData): void
{
    mock(ProjectsService::class, function (MockInterface $mock) use ($workspacePath, $projectData) {
        $mock->shouldReceive('loadProjectWorkspace')
            ->once()
            ->with($workspacePath)
            ->andReturn(componentWorkspaceData($workspacePath));

        $mock->shouldReceive('loadProjectFromWorkspace')
            ->once()
            ->with($workspacePath)
            ->andReturn($projectData);
    });
}

/**
 * Assert the workflow never reaches the queue.
 */
function cliToolsExpectNoDispatch(): void
{
    mock(WorkflowService::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('dispatchWorkflow');
    });
}
