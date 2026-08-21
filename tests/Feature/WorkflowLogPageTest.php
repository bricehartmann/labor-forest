<?php

use App\Data\WorkflowRunLogData;
use App\Data\WorkspaceData;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowStepSkipReason;
use App\Events\WorkflowFinished;
use App\Events\WorkflowStarted;
use App\Events\WorkflowStepFinished;
use App\Events\WorkflowStepOutputUpdated;
use App\Events\WorkflowStepSkipped;
use App\Events\WorkflowStepStarted;
use App\Exceptions\ProjectNotFound;
use App\Filament\Pages\Project;
use App\Filament\Pages\WorkflowLog;
use App\Services\ProjectsService;
use App\Services\WorkflowService;
use Livewire\Livewire;
use Mockery\MockInterface;

beforeEach(function () {
    $this->uuid = '4f0f6c6e-1e21-4a2f-9a3e-8c6a0d2b7f11';
    $this->slug = 'repo-feature';
    $this->logId = '20240101T000000Z_repo-feature_up';
    $this->parentLogId = '20240101T000000Z_repo-feature_deploy';

    $this->workspaces = collect([componentWorkspaceData()]);
    $this->logsById = [$this->logId => workflowLogPageRunLog($this->logId)];

    $this->loadProjectThrows = null;
    $this->loadedProjectUuids = [];
    $this->loadedLogIds = [];

    $this->mock(ProjectsService::class, function (MockInterface $mock) {
        $mock->shouldReceive('loadProject')->andReturnUsing(function (string $uuid) {
            if ($this->loadProjectThrows !== null) {
                throw $this->loadProjectThrows;
            }

            $this->loadedProjectUuids[] = $uuid;

            return componentProjectData($uuid);
        });

        $mock->shouldReceive('loadProjectWorkspaces')->andReturnUsing(fn () => $this->workspaces);
    });

    $this->mock(WorkflowService::class, function (MockInterface $mock) {
        $mock->shouldReceive('loadWorkflowLogDatum')->andReturnUsing(
            function (WorkspaceData $workspace, string $id) {
                $this->loadedLogIds[] = $id;

                return $this->logsById[$id] ?? null;
            }
        );
    });

    $this->mountPage = fn () => Livewire::test(WorkflowLog::class, [
        'uuid' => $this->uuid,
        'slug' => $this->slug,
        'id' => $this->logId,
    ]);
});

describe('mount', function () {
    it('loads the run log and renders its status', function () {
        ($this->mountPage)()
            ->assertOk()
            ->assertSet('loadedInvalidMessage', null)
            ->assertSee($this->logId)
            ->assertSee('success')
            ->assertSee('Install dependencies');

        expect($this->loadedProjectUuids)->toBe([$this->uuid])
            ->and($this->loadedLogIds)->toBe([$this->logId]);
    });

    it('renders the status of a failed run', function () {
        $this->logsById = [$this->logId => workflowLogPageRunLog($this->logId, status: WorkflowStatus::FAILED)];

        ($this->mountPage)()
            ->assertOk()
            ->assertSee('failed');
    });

    it('records the load failure instead of throwing', function () {
        $this->loadProjectThrows = new ProjectNotFound($this->uuid);

        ($this->mountPage)()
            ->assertOk()
            ->assertSet('loadedInvalidMessage', "Project with UUID '{$this->uuid}' not found.")
            ->assertSet('workflowRunLog', []);
    });

    it('redirects to the project when no workspace matches the slug', function () {
        $this->workspaces = collect([componentWorkspaceData(path: '/tmp/repo-other')]);

        ($this->mountPage)()
            ->assertRedirect(Project::getUrl(['uuid' => $this->uuid]));

        expect($this->loadedLogIds)->toBe([]);
    });

    it('redirects to the project when the run log does not exist', function () {
        $this->logsById = [];

        ($this->mountPage)()
            ->assertRedirect(Project::getUrl(['uuid' => $this->uuid]));

        expect($this->loadedLogIds)->toBe([$this->logId]);
    });
});

describe('parentRunLogData', function () {
    beforeEach(function () {
        $this->logsById = [
            $this->logId => workflowLogPageRunLog($this->logId, parent: $this->parentLogId),
            $this->parentLogId => workflowLogPageRunLog($this->parentLogId, name: 'deploy'),
        ];
    });

    it('links to the log of the run that started this one', function () {
        ($this->mountPage)()
            ->assertOk()
            ->assertSee('Started by')
            ->assertSee('Deploy')
            ->assertSee(WorkflowLog::getUrl([
                'uuid' => $this->uuid,
                'slug' => $this->slug,
                'id' => $this->parentLogId,
            ]));

        expect($this->loadedLogIds)->toBe([$this->logId, $this->parentLogId]);
    });

    it('falls back to the raw parent id once the parent log is gone', function () {
        $this->logsById = [$this->logId => workflowLogPageRunLog($this->logId, parent: $this->parentLogId)];

        ($this->mountPage)()
            ->assertOk()
            ->assertSee('Started by')
            ->assertSee($this->parentLogId)
            ->assertDontSee(WorkflowLog::getUrl([
                'uuid' => $this->uuid,
                'slug' => $this->slug,
                'id' => $this->parentLogId,
            ]));
    });
});

describe('onWorkflowStarted', function () {
    beforeEach(function () {
        $this->logsById = [$this->logId => workflowLogPageRunLog($this->logId, status: WorkflowStatus::RUNNING)];
    });

    it('reloads and scrolls to the first step of this run', function () {
        ($this->mountPage)()
            ->dispatch(
                'native:'.WorkflowStarted::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
                workflowName: 'up',
            )
            ->assertDispatched('scroll-to-step', stepHash: 'aaa111');

        expect($this->loadedLogIds)->toBe([$this->logId, $this->logId]);
    });

    it('ignores a run belonging to another project', function () {
        ($this->mountPage)()
            ->dispatch(
                'native:'.WorkflowStarted::class,
                projectUuid: 'another-uuid',
                workspaceSlugKebab: $this->slug,
                workflowName: 'up',
            )
            ->assertNotDispatched('scroll-to-step');

        expect($this->loadedLogIds)->toBe([$this->logId]);
    });
});

describe('onWorkflowFinished', function () {
    beforeEach(function () {
        $this->logsById = [$this->logId => workflowLogPageRunLog($this->logId, status: WorkflowStatus::RUNNING)];
    });

    it('reloads and scrolls back to the top when this run finishes', function () {
        ($this->mountPage)()
            ->dispatch(
                'native:'.WorkflowFinished::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
                workflowName: 'up',
                status: WorkflowStatus::SUCCESS->value,
                logId: $this->logId,
            )
            ->assertDispatched('scroll-to-top');

        expect($this->loadedLogIds)->toBe([$this->logId, $this->logId]);
    });

    it('ignores the finish of a different run of the same workflow', function () {
        ($this->mountPage)()
            ->dispatch(
                'native:'.WorkflowFinished::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
                workflowName: 'up',
                status: WorkflowStatus::SUCCESS->value,
                logId: '20240202T000000Z_repo-feature_up',
            )
            ->assertNotDispatched('scroll-to-top');

        expect($this->loadedLogIds)->toBe([$this->logId]);
    });

    it('ignores a run in another workspace', function () {
        ($this->mountPage)()
            ->dispatch(
                'native:'.WorkflowFinished::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: 'repo-other',
                workflowName: 'up',
                status: WorkflowStatus::SUCCESS->value,
                logId: $this->logId,
            )
            ->assertNotDispatched('scroll-to-top');

        expect($this->loadedLogIds)->toBe([$this->logId]);
    });
});

describe('onWorkflowStepStarted', function () {
    beforeEach(function () {
        $this->logsById = [$this->logId => workflowLogPageRunLog($this->logId, status: WorkflowStatus::RUNNING)];
    });

    it('reloads and scrolls to the started step', function () {
        ($this->mountPage)()
            ->dispatch(
                'native:'.WorkflowStepStarted::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
                workflowName: 'up',
                stepHash: 'bbb222',
            )
            ->assertDispatched('scroll-to-step', stepHash: 'bbb222');

        expect($this->loadedLogIds)->toBe([$this->logId, $this->logId]);
    });

    it('ignores a step of another workflow', function () {
        ($this->mountPage)()
            ->dispatch(
                'native:'.WorkflowStepStarted::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
                workflowName: 'down',
                stepHash: 'bbb222',
            )
            ->assertNotDispatched('scroll-to-step');

        expect($this->loadedLogIds)->toBe([$this->logId]);
    });

    it('ignores a step event once this run is no longer locked', function () {
        $this->logsById = [$this->logId => workflowLogPageRunLog($this->logId, status: WorkflowStatus::SUCCESS)];

        ($this->mountPage)()
            ->dispatch(
                'native:'.WorkflowStepStarted::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
                workflowName: 'up',
                stepHash: 'bbb222',
            )
            ->assertNotDispatched('scroll-to-step');

        expect($this->loadedLogIds)->toBe([$this->logId]);
    });
});

describe('onWorkflowStepFinished', function () {
    beforeEach(function () {
        $this->logsById = [$this->logId => workflowLogPageRunLog($this->logId, status: WorkflowStatus::RUNNING)];
    });

    it('reloads and scrolls to the finished step', function () {
        ($this->mountPage)()
            ->dispatch(
                'native:'.WorkflowStepFinished::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
                workflowName: 'up',
                stepHash: 'aaa111',
                status: WorkflowStatus::SUCCESS->value,
            )
            ->assertDispatched('scroll-to-step', stepHash: 'aaa111');

        expect($this->loadedLogIds)->toBe([$this->logId, $this->logId]);
    });

    it('ignores a step finished in another workspace', function () {
        ($this->mountPage)()
            ->dispatch(
                'native:'.WorkflowStepFinished::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: 'repo-other',
                workflowName: 'up',
                stepHash: 'aaa111',
                status: WorkflowStatus::SUCCESS->value,
            )
            ->assertNotDispatched('scroll-to-step');

        expect($this->loadedLogIds)->toBe([$this->logId]);
    });
});

describe('onWorkflowStepSkipped', function () {
    beforeEach(function () {
        $this->logsById = [$this->logId => workflowLogPageRunLog($this->logId, status: WorkflowStatus::RUNNING)];
    });

    it('reloads and scrolls to the skipped step', function () {
        ($this->mountPage)()
            ->dispatch(
                'native:'.WorkflowStepSkipped::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
                workflowName: 'up',
                stepHash: 'bbb222',
                reason: WorkflowStepSkipReason::NOT_SELECTED->value,
            )
            ->assertDispatched('scroll-to-step', stepHash: 'bbb222');

        expect($this->loadedLogIds)->toBe([$this->logId, $this->logId]);
    });

    it('ignores a skipped step of another project', function () {
        ($this->mountPage)()
            ->dispatch(
                'native:'.WorkflowStepSkipped::class,
                projectUuid: 'another-uuid',
                workspaceSlugKebab: $this->slug,
                workflowName: 'up',
                stepHash: 'bbb222',
                reason: WorkflowStepSkipReason::NOT_SELECTED->value,
            )
            ->assertNotDispatched('scroll-to-step');

        expect($this->loadedLogIds)->toBe([$this->logId]);
    });
});

describe('onWorkflowStepOutputUpdated', function () {
    beforeEach(function () {
        $this->logsById = [$this->logId => workflowLogPageRunLog($this->logId, status: WorkflowStatus::RUNNING)];
    });

    it('patches the streamed output into the matching step without reloading', function () {
        ($this->mountPage)()
            ->dispatch(
                'native:'.WorkflowStepOutputUpdated::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
                workflowName: 'up',
                stepHash: 'aaa111',
                output: 'installing…',
            )
            ->assertDispatched('scroll-to-step', stepHash: 'aaa111')
            ->assertSet('workflowRunLog.steps.0.output', 'installing…');

        expect($this->loadedLogIds)->toBe([$this->logId]);
    });

    it('leaves every step untouched when the hash matches none of them', function () {
        ($this->mountPage)()
            ->dispatch(
                'native:'.WorkflowStepOutputUpdated::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
                workflowName: 'up',
                stepHash: 'zzz999',
                output: 'installing…',
            )
            ->assertNotDispatched('scroll-to-step')
            ->assertSet('workflowRunLog.steps.0.output', 'done');
    });

    it('ignores output broadcast for another workflow', function () {
        ($this->mountPage)()
            ->dispatch(
                'native:'.WorkflowStepOutputUpdated::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
                workflowName: 'down',
                stepHash: 'aaa111',
                output: 'installing…',
            )
            ->assertNotDispatched('scroll-to-step')
            ->assertSet('workflowRunLog.steps.0.output', 'done');
    });
});

/**
 * A two step run log of the up workflow, hashed so a broadcast can address either step.
 */
function workflowLogPageRunLog(
    string $id,
    string $name = 'up',
    ?string $parent = null,
    WorkflowStatus $status = WorkflowStatus::SUCCESS,
): WorkflowRunLogData {
    return componentRunLogData(
        id: $id,
        name: $name,
        parent: $parent,
        status: $status,
        steps: [
            componentRunLogStepData(hash: 'aaa111'),
            componentRunLogStepData(name: 'Run migrations', run: 'php artisan migrate', hash: 'bbb222'),
        ],
    );
}
