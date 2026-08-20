<?php

use App\Data\ProjectData;
use App\Data\WorkflowData;
use App\Data\WorkflowRunLogData;
use App\Data\WorkflowRunLogStepData;
use App\Data\WorkflowStepData;
use App\Data\WorkspaceData;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowStepFailureReason;
use App\Enums\WorkflowStepSkipReason;
use App\Enums\WorkflowStepStatus;
use App\Enums\WorkflowStepType;
use App\Enums\WorkspaceStatus;
use App\Jobs\RunWorkflow;
use App\Services\ProcessEnvironmentService;
use App\Services\ProjectsService;
use App\Services\WorkflowService;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Mockery\MockInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;
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

describe('gates', function () {
    beforeEach(function () {
        $this->workspacePath = '/tmp/repo-feature';
        $this->project = componentProjectData('11111111-1111-1111-1111-111111111111', '/tmp/repo');
        $this->workspace = componentWorkspaceData($this->workspacePath);

        $this->instance(ProcessEnvironmentService::class, new FakeProcessEnvironmentService);

        $this->mock(WorkflowService::class, function (MockInterface $mock) {
            $mock->shouldReceive('writeWorkflowLogData');
        });

        // runSteps() reads the run log the job has already written, so the fixture stands in for it
        $this->runGatedSteps = function (array $steps): array {
            $workflow = componentWorkflowData($steps);

            $job = exposedRunWorkflow(45, stepHashes: $workflow->stepHashes());
            $job->logFilePath = $this->workspacePath.'/.laborforest/ignored/logs/deploy.yaml';
            $job->workflowRunLogData = componentRunLogData(
                id: 'deploy-log',
                name: 'deploy',
                steps: $workflow->steps
                    ->values()
                    ->map(fn (WorkflowStepData $step, int $index) => componentRunLogStepData(
                        name: $step->name,
                        exitCode: null,
                        output: '',
                        hash: $step->hash((string) $index),
                        run: $step->run,
                    ))
                    ->all(),
            );

            return [
                'successful' => $job->steps($workflow, $this->project, $this->workspace),
                'steps' => $job->workflowRunLogData->steps,
            ];
        };
    });

    it('skips the step and carries on when an if gate exits non-zero', function () {
        // a gate's exit code is an answer, so even a missing command reads as false rather than
        // as the gate itself having broken
        Process::fake([
            'which nope' => Process::result(exitCode: 127),
            '*' => Process::result('done'),
        ]);

        $run = ($this->runGatedSteps)([
            componentStepData(name: 'Gated', run: 'composer install', if: 'which nope'),
            componentStepData(name: 'After', run: 'php artisan migrate'),
        ]);

        expect($run['successful'])->toBeTrue()
            ->and($run['steps']->first()->skip_reason)->toBe(WorkflowStepSkipReason::IF_FAILED)
            ->and($run['steps']->first()->failure_reason)->toBeNull()
            ->and($run['steps']->last()->exitCode)->toBe(0);
    });

    it('skips the step and carries on when an unless gate exits zero', function () {
        Process::fake(['*' => Process::result('done')]);

        $run = ($this->runGatedSteps)([
            componentStepData(name: 'Gated', run: 'composer install', unless: 'test -f .skip'),
            componentStepData(name: 'After', run: 'php artisan migrate'),
        ]);

        expect($run['successful'])->toBeTrue()
            ->and($run['steps']->first()->skip_reason)->toBe(WorkflowStepSkipReason::UNLESS_MATCHED)
            ->and($run['steps']->last()->exitCode)->toBe(0);
    });

    it('fails the run at the step whose if gate could not be run', function () {
        Process::fake(['*' => fn () => throw new RuntimeException('sh: not executable')]);

        $run = ($this->runGatedSteps)([
            componentStepData(name: 'Gated', run: 'composer install', if: 'which nope'),
            componentStepData(name: 'After', run: 'php artisan migrate'),
        ]);

        $gated = $run['steps']->first();

        expect($run['successful'])->toBeFalse()
            ->and($gated->failure_reason)->toBe(WorkflowStepFailureReason::IF_GATE_FAILED)
            ->and($gated->skip_reason)->toBeNull()
            ->and($gated->exitCode)->toBe(1)
            ->and($gated->output)->toContain('the if condition could not be run')
            ->and($gated->output)->toContain('sh: not executable')
            ->and($gated->status())->toBe(WorkflowStepStatus::FAILED);
    });

    it('fails the run at the step whose unless gate could not be run', function () {
        Process::fake(['*' => fn () => throw new RuntimeException('sh: not executable')]);

        $run = ($this->runGatedSteps)([
            componentStepData(name: 'Gated', run: 'composer install', unless: 'test -f .skip'),
        ]);

        expect($run['successful'])->toBeFalse()
            ->and($run['steps']->first()->failure_reason)->toBe(WorkflowStepFailureReason::UNLESS_GATE_FAILED);
    });

    it('tells a timed-out gate apart from one that could not be run, keeping its exit code and stderr', function () {
        Process::fake(['*' => fn () => throw timedOutProcessException()]);

        $run = ($this->runGatedSteps)([
            componentStepData(name: 'Gated', run: 'composer install', if: 'sleep 90'),
        ]);

        $gated = $run['steps']->first();

        expect($run['successful'])->toBeFalse()
            ->and($gated->failure_reason)->toBe(WorkflowStepFailureReason::IF_GATE_TIMED_OUT)
            ->and($gated->exitCode)->toBe(143)
            ->and($gated->output)->toContain('the if condition ran out of time')
            ->and($gated->output)->toContain('killed by the timeout');
    });

    it('attributes an unresolved variable in a gate to the step that declared it', function () {
        // the gate never reaches a process, so this fails before anything is spawned
        $run = ($this->runGatedSteps)([
            componentStepData(name: 'Gated', run: 'composer install', if: 'test -n "{{ ENV_MISSING }}"'),
        ]);

        expect($run['successful'])->toBeFalse()
            ->and($run['steps']->first()->failure_reason)->toBe(WorkflowStepFailureReason::IF_GATE_FAILED)
            ->and($run['steps']->first()->output)->toContain("Environment variable 'MISSING' not found");
    });

    it('leaves the steps behind a broken gate aborted rather than failed', function () {
        Process::fake(['*' => fn () => throw new RuntimeException('sh: not executable')]);

        $run = ($this->runGatedSteps)([
            componentStepData(name: 'Gated', run: 'composer install', if: 'which nope'),
            componentStepData(name: 'After', run: 'php artisan migrate'),
        ]);

        // markUnreachedStepsAborted() runs from handle(), so stand in for it the way handle() would
        expect($run['steps']->last()->isPending())->toBeTrue()
            ->and($run['steps']->first()->isPending())->toBeFalse();
    });
});

describe('shell step timeout', function () {
    it('fails a timed-out shell step rather than leaving it indistinguishable from an aborted one', function () {
        $this->instance(ProcessEnvironmentService::class, new FakeProcessEnvironmentService);

        $this->mock(WorkflowService::class, function (MockInterface $mock) {
            $mock->shouldReceive('writeWorkflowLogData');
        });

        Process::fake(['*' => fn () => throw timedOutProcessException()]);

        $workflow = componentWorkflowData([componentStepData(name: 'Install', run: 'composer install')]);

        $job = exposedRunWorkflow(45, stepHashes: $workflow->stepHashes());
        $job->logFilePath = '/tmp/repo-feature/.laborforest/ignored/logs/deploy.yaml';
        $job->workflowRunLogData = componentRunLogData(
            id: 'deploy-log',
            name: 'deploy',
            steps: [componentRunLogStepData(name: 'Install', exitCode: null, output: '', hash: $workflow->steps->first()->hash('0'))],
        );

        $successful = $job->steps($workflow, componentProjectData('11111111-1111-1111-1111-111111111111', '/tmp/repo'), componentWorkspaceData('/tmp/repo-feature'));

        $step = $job->workflowRunLogData->step(0);

        expect($successful)->toBeFalse()
            ->and($step->failure_reason)->toBe(WorkflowStepFailureReason::TIMEOUT)
            ->and($step->exitCode)->toBe(143)
            ->and($step->skip_reason)->toBeNull()
            ->and($step->output)->toContain('the step ran out of time')
            ->and($step->status())->toBe(WorkflowStepStatus::FAILED);
    });
});

/**
 * The exception Laravel raises when a spawned process outlives its budget, carrying the result of
 * the process it killed the way a real timeout does.
 */
function timedOutProcessException(): ProcessTimedOutException
{
    return new ProcessTimedOutException(
        new SymfonyProcessTimedOutException(new SymfonyProcess(['sleep', '90']), SymfonyProcessTimedOutException::TYPE_GENERAL),
        Process::result(output: '', errorOutput: 'killed by the timeout', exitCode: 143),
    );
}

/**
 * A job carrying nothing but the timeout under test, since pendingProcess() reads no other state.
 */
function exposedRunWorkflow(int $timeoutSeconds, array $ancestorWorkflowNames = [], array $stepHashes = []): ExposedRunWorkflow
{
    return new ExposedRunWorkflow(
        timestamp: 0,
        projectUuid: '11111111-1111-1111-1111-111111111111',
        workspacePath: '/tmp/repo-feature',
        workflowName: 'deploy',
        stepHashes: $stepHashes,
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
     * Run a whole workflow's steps against an already-populated run log, so gate handling can be
     * asserted without a workflow file on disk or a queue behind it.
     */
    public function steps(WorkflowData $workflowData, ProjectData $projectData, WorkspaceData $workspaceData): bool
    {
        return $this->runSteps($workflowData, $projectData, $workspaceData);
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
