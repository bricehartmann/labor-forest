<?php

use App\Data\ProjectData;
use App\Data\WorkflowRunLogData;
use App\Data\WorkflowRunLogStepData;
use App\Data\WorkflowStepData;
use App\Data\WorkspaceData;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowStepType;
use App\Enums\WorkspaceStatus;
use App\Jobs\RunWorkflow;
use App\Services\ProcessEnvironmentService;
use App\Services\ProjectsService;
use App\Services\WorkflowService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Mockery\MockInterface;
use Tests\Fakes\FakeProcessEnvironmentService;

describe('pendingProcess', function () {
    it('gives every process it builds the configured budget', function () {
        $process = exposedRunWorkflow(45)->pendingStepProcess('/tmp/repo-feature', ['FOO' => 'bar']);

        expect($process->timeout)->toBe(45)
            ->and($process->path)->toBe('/tmp/repo-feature')
            ->and($process->environment)->toBe(['FOO' => 'bar']);
    });

    it('leaves the process unbounded when the budget is zero', function () {
        expect(exposedRunWorkflow(0)->pendingStepProcess('/tmp/repo-feature', [])->timeout)->toBeNull();
    });
});

describe('queue payload', function () {
    it('does not carry a job timeout the worker would kill the whole run at', function () {
        // Laravel serializes a job property named `timeout` into the queue payload, and
        // Worker::timeoutForJob() then prefers it over the worker's own timeout. Naming the
        // per-process budget `timeout` would therefore cap a whole multi-step run at the number of
        // seconds meant for each single step.
        expect(property_exists(new RunWorkflow(0, 'project-uuid', '/tmp/repo-feature', 'up', []), 'timeout'))->toBeFalse();
    });
});

describe('resolveFinalStatus', function () {
    it('leaves the workspace in error when a step failed', function () {
        expect(exposedRunWorkflow(0)->finalStatus(false, WorkspaceStatus::READY, WorkspaceStatus::SUSPENDED))
            ->toBe(WorkspaceStatus::ERROR);
    });

    it('applies the ending status the workflow declares', function () {
        expect(exposedRunWorkflow(0)->finalStatus(true, WorkspaceStatus::READY, WorkspaceStatus::SUSPENDED))
            ->toBe(WorkspaceStatus::READY);
    });

    it('returns the workspace to where it started when the workflow declares no ending status', function () {
        // the status on disk is `pending` by then, and leaving the workspace there would block every
        // later workflow, since `pending` allows none
        expect(exposedRunWorkflow(0)->finalStatus(true, null, WorkspaceStatus::SUSPENDED))
            ->toBe(WorkspaceStatus::SUSPENDED);
    });
});

describe('runChildWorkflow', function () {
    beforeEach(function () {
        $this->uuid = '11111111-1111-1111-1111-111111111111';
        $this->workspacePath = '/tmp/repo-feature';
        $this->project = componentProjectData($this->uuid, '/tmp/repo');
        $this->workspace = componentWorkspaceData($this->workspacePath);

        $this->instance(ProcessEnvironmentService::class, new FakeProcessEnvironmentService);

        // the child workflow the step names, whose every step the child job is expected to run
        $this->childWorkflow = componentWorkflowData([
            componentStepData(name: 'Install dependencies', run: 'composer install'),
            componentStepData(name: 'Migrate', run: 'php artisan migrate'),
        ]);

        $this->writtenLogs = collect();

        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProject')->andReturn($this->project);
            $mock->shouldReceive('loadProjectWorkspace')->andReturn($this->workspace);
            $mock->shouldReceive('updateProjectWorkspaceStatus');
        });

        $this->mock(WorkflowService::class, function (MockInterface $mock) {
            $mock->shouldReceive('workflowPath')->andReturnUsing(
                fn (string $path, string $name) => $path.'/.laborforest/workflows/'.$name.'.yaml',
            );
            $mock->shouldReceive('loadWorkflow')->andReturn($this->childWorkflow);
            $mock->shouldReceive('availableLogTimestamp')->andReturn(1704067200);
            $mock->shouldReceive('ensureLogFilePathDirectoryExists')
                ->andReturn($this->workspacePath.'/.laborforest/ignored/logs');
            $mock->shouldReceive('writeWorkflowLogData')->andReturnUsing(
                fn (string $path, WorkflowRunLogData $log) => $this->writtenLogs->put($log->id, $log),
            );
            $mock->shouldReceive('workflowRunLogData')->andReturnUsing(
                fn (int $timestamp, WorkspaceData $workspace, string $name, ?string $parentLogId, WorkflowStatus $status, Collection $workflowSteps) => componentRunLogData(
                    id: $timestamp.'_repo-feature_'.$name,
                    name: $name,
                    parent: $parentLogId,
                    timestamp: $timestamp,
                    status: $status,
                    steps: $workflowSteps
                        ->values()
                        ->map(fn (WorkflowStepData $step, int $index) => componentRunLogStepData(
                            name: $step->name,
                            exitCode: null,
                            output: '',
                            hash: $step->hash((string) $index),
                            run: $step->run,
                        ))
                        ->all(),
                ),
            );
        });

        $this->runChild = function (): bool {
            $parent = exposedRunWorkflow(45);
            $parent->logFilePath = $this->workspacePath.'/.laborforest/ignored/logs/parent-log.yaml';
            $parent->workflowRunLogData = componentRunLogData(
                id: 'parent-log',
                name: 'deploy',
                steps: [componentRunLogStepData(name: 'Run up', type: WorkflowStepType::WORKFLOW, run: 'up')],
            );

            $this->parentLogStep = $parent->workflowRunLogData->step(0);

            return $parent->childWorkflow(
                componentStepData(name: 'Run up', run: 'up', type: WorkflowStepType::WORKFLOW),
                $this->parentLogStep,
                $this->project,
                $this->workspace,
            );
        };
    });

    it('runs every step of the child workflow rather than a selection of them', function () {
        // the child is handed WorkflowData::stepHashes(), so nothing it declares is skipped as
        // unselected the way a hand-picked run from the project screen would be
        Process::fake(['*' => Process::result('done')]);

        expect(($this->runChild)())->toBeTrue();

        $childLog = $this->writtenLogs->get('1704067200_repo-feature_up');

        expect($childLog->steps->pluck('skip_reason')->all())->toBe([null, null])
            ->and($childLog->steps->pluck('exitCode')->all())->toBe([0, 0]);
    });

    it('links the child run to the run log of the workflow that started it', function () {
        Process::fake(['*' => Process::result('done')]);

        ($this->runChild)();

        $childLog = $this->writtenLogs->get('1704067200_repo-feature_up');

        expect($childLog->parent)->toBe('parent-log')
            ->and($this->parentLogStep->log_id)->toBe($childLog->id)
            ->and($this->parentLogStep->exitCode)->toBe(0)
            ->and($this->parentLogStep->output)->toBe('Workflow [up] finished with status [success].');
    });

    it('fails the step that started a child workflow already running in the chain', function () {
        $parent = exposedRunWorkflow(45, ancestorWorkflowNames: ['up']);
        $parent->logFilePath = $this->workspacePath.'/.laborforest/ignored/logs/parent-log.yaml';
        $parent->workflowRunLogData = componentRunLogData(id: 'parent-log', name: 'deploy');

        $logStep = componentRunLogStepData(name: 'Run up', type: WorkflowStepType::WORKFLOW, run: 'up');

        expect($parent->childWorkflow(
            componentStepData(name: 'Run up', run: 'up', type: WorkflowStepType::WORKFLOW),
            $logStep,
            $this->project,
            $this->workspace,
        ))->toBeFalse()
            ->and($logStep->output)->toBe('Workflow [up] is already running in this chain: up > deploy.');
    });
});

/**
 * A job carrying nothing but the timeout under test, since pendingProcess() reads no other state.
 */
function exposedRunWorkflow(int $timeoutSeconds, array $ancestorWorkflowNames = []): ExposedRunWorkflow
{
    return new ExposedRunWorkflow(
        timestamp: 0,
        projectUuid: '11111111-1111-1111-1111-111111111111',
        workspacePath: '/tmp/repo-feature',
        workflowName: 'deploy',
        stepHashes: [],
        timeoutSeconds: $timeoutSeconds,
        ancestorWorkflowNames: $ancestorWorkflowNames,
        statusBeforeRun: WorkspaceStatus::READY,
    );
}

/**
 * A RunWorkflow that exposes the pending process it builds for a step, so the budget it carries can
 * be asserted without running a workflow.
 */
final class ExposedRunWorkflow extends RunWorkflow
{
    /**
     * @param  array<string, string|false>  $env
     */
    public function pendingStepProcess(string $cwd, array $env): PendingProcess
    {
        return $this->pendingProcess($cwd, $env);
    }

    public function finalStatus(bool $allSuccessful, ?WorkspaceStatus $endingStatus, WorkspaceStatus $statusBeforeRun): WorkspaceStatus
    {
        return $this->resolveFinalStatus($allSuccessful, $endingStatus, $statusBeforeRun);
    }

    /**
     * Run one `workflow` step, so the job the child is dispatched as can be inspected without a
     * workflow file on disk.
     */
    public function childWorkflow(WorkflowStepData $step, WorkflowRunLogStepData $logStep, ProjectData $projectData, WorkspaceData $workspaceData): bool
    {
        return $this->runChildWorkflow($step, $logStep, $projectData, $workspaceData, []);
    }
}
