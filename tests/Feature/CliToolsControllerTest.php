<?php

use App\Data\ProjectData;
use App\Data\SettingsData;
use App\Exceptions\InvalidSettingsFile;
use App\Exceptions\InvalidWorkflowFile;
use App\Exceptions\ProjectDirectoryNotGitRepository;
use App\Exceptions\WorkspaceNotFound;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Project;
use App\Filament\Pages\WorkflowLog;
use App\Services\ProjectsService;
use App\Services\SettingsService;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

use function Pest\Laravel\mock;

beforeEach(function () {
    Storage::fake('user_home');

    $this->uuid = '11111111-1111-1111-1111-111111111111';
    $this->workspacePath = '/tmp/repo-feature';
    $this->workflowPath = '/tmp/repo-feature/.laborforest/workflows/up.yaml';

    /**
     * The paths File::isDirectory() and File::isFile() report as existing. Nothing is ever created
     * on disk; each test declares what the controller should find.
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

    File::partialMock()
        ->shouldReceive('isDirectory')
        ->andReturnUsing(fn (string $path) => in_array($path, $this->existingPaths))
        ->shouldReceive('isFile')
        ->andReturnUsing(function (string $path) {
            $this->checkedFilePaths[] = $path;

            return in_array($path, $this->existingPaths);
        });
});

describe('addProject', function () {
    it('adds the project and redirects to its page', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('addProject')
                ->once()
                ->with($this->workspacePath)
                ->andReturn(componentProjectData($this->uuid, $this->workspacePath));
        });

        $this->get('/add-project?path='.urlencode($this->workspacePath))
            ->assertRedirect(Project::getUrl(['uuid' => $this->uuid]))
            ->assertSessionMissing('error');
    });

    it('redirects to the dashboard when no path is given', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('addProject');
        });

        $this->get('/add-project')
            ->assertRedirect(Dashboard::getUrl())
            ->assertSessionHas('error', 'Path does not exist.');
    });

    it('redirects to the dashboard when the path is not a directory', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('addProject');
        });

        $this->get('/add-project?path='.urlencode('/tmp/nope'))
            ->assertRedirect(Dashboard::getUrl())
            ->assertSessionHas('error', 'Path does not exist.');
    });

    it('reports the failure when the service throws', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('addProject')
                ->once()
                ->andThrow(new ProjectDirectoryNotGitRepository($this->workspacePath));
        });

        $this->get('/add-project?path='.urlencode($this->workspacePath))
            ->assertRedirect(Dashboard::getUrl())
            ->assertSessionHas('error', "Project with directory '{$this->workspacePath}' is not a git repository.");
    });
});

describe('runWorkflow', function () {
    it('dispatches the workflow and redirects to its log', function () {
        $steps = [componentStepData(), componentStepData(name: 'Migrate', run: 'php artisan migrate')];
        $expectedHashes = [$steps[0]->hash('0'), $steps[1]->hash('1')];

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

        $this->get(cliToolsRunWorkflowUrl($this->workspacePath, 'up'))
            ->assertRedirect(WorkflowLog::getUrl([
                'uuid' => $this->uuid,
                'slug' => 'repo-feature',
                'id' => '20240101T000000Z_repo-feature_up',
            ]))
            ->assertSessionMissing('error');
    });

    it('resolves the workflow file inside the workspace', function () {
        cliToolsMockProjectsService($this->workspacePath, componentProjectData($this->uuid, '/tmp/repo'));

        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')->andReturn(new SettingsData);
        });

        $this->mock(WorkflowService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSteps')->andReturn(collect([componentStepData()]));
            $mock->shouldReceive('dispatchWorkflow')->andReturn('20240101T000000Z_repo-feature_up');
        });

        $this->get(cliToolsRunWorkflowUrl($this->workspacePath, 'up'));

        expect($this->checkedFilePaths)->toBe([$this->workflowPath]);
    });

    it('redirects to the dashboard when no path is given', function () {
        cliToolsExpectNoDispatch();

        $this->get('/run-workflow?workflow=up')
            ->assertRedirect(Dashboard::getUrl())
            ->assertSessionHas('error', 'Path does not exist.');
    });

    it('redirects to the dashboard when the path is not a directory', function () {
        cliToolsExpectNoDispatch();

        $this->get(cliToolsRunWorkflowUrl('/tmp/nope', 'up'))
            ->assertRedirect(Dashboard::getUrl())
            ->assertSessionHas('error', 'Path does not exist.');
    });

    it('redirects to the dashboard when no workflow is given', function () {
        cliToolsExpectNoDispatch();

        $this->get('/run-workflow?path='.urlencode($this->workspacePath))
            ->assertRedirect(Dashboard::getUrl())
            ->assertSessionHas('error', 'Workflow does not exist.');
    });

    it('redirects to the dashboard when the workflow file does not exist', function () {
        cliToolsExpectNoDispatch();

        $this->get(cliToolsRunWorkflowUrl($this->workspacePath, 'down'))
            ->assertRedirect(Dashboard::getUrl())
            ->assertSessionHas('error', 'Workflow does not exist.');

        expect($this->checkedFilePaths)->toBe(['/tmp/repo-feature/.laborforest/workflows/down.yaml']);
    });

    it('reports the failure when the workspace is not found', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjectWorkspace')
                ->once()
                ->andThrow(new WorkspaceNotFound($this->workspacePath));
            $mock->shouldNotReceive('loadProjectFromWorkspace');
        });

        cliToolsExpectNoDispatch();

        $this->get(cliToolsRunWorkflowUrl($this->workspacePath, 'up'))
            ->assertRedirect(Dashboard::getUrl())
            ->assertSessionHas('error', "Workspace at path '{$this->workspacePath}' not found.");
    });

    it('reports the failure when the workspace belongs to no registered project', function () {
        cliToolsMockProjectsService($this->workspacePath, null);
        cliToolsExpectNoDispatch();

        $this->get(cliToolsRunWorkflowUrl($this->workspacePath, 'up'))
            ->assertRedirect(Dashboard::getUrl())
            ->assertSessionHas('error', 'Project does not exist.');
    });

    it('reports the failure when the settings file is invalid', function () {
        cliToolsMockProjectsService($this->workspacePath, componentProjectData($this->uuid, '/tmp/repo'));

        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')
                ->once()
                ->andThrow(InvalidSettingsFile::notAMapping('.laborforest/settings.yaml', 'string'));
        });

        cliToolsExpectNoDispatch();

        $this->get(cliToolsRunWorkflowUrl($this->workspacePath, 'up'))
            ->assertRedirect(Dashboard::getUrl())
            ->assertSessionHas('error', 'The settings file [.laborforest/settings.yaml] is invalid: Expected a mapping, found string.');
    });

    it('reports the failure when the workflow file is invalid', function () {
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

        $this->get(cliToolsRunWorkflowUrl($this->workspacePath, 'up'))
            ->assertRedirect(Dashboard::getUrl())
            ->assertSessionHas('error', "The workflow file [{$this->workflowPath}] is invalid: The steps field is required.");
    });

    it('reports the failure when dispatching throws', function () {
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

        $this->get(cliToolsRunWorkflowUrl($this->workspacePath, 'up'))
            ->assertRedirect(Dashboard::getUrl())
            ->assertSessionHas('error', "Workspace at path '{$this->workspacePath}' not found.");
    });
});

/**
 * The run-workflow URL with both query parameters encoded.
 */
function cliToolsRunWorkflowUrl(string $path, string $workflow): string
{
    return '/run-workflow?path='.urlencode($path).'&workflow='.urlencode($workflow);
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
