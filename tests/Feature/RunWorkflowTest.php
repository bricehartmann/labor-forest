<?php

use App\Enums\WorkspaceStatus;
use App\Jobs\RunWorkflow;
use Illuminate\Process\PendingProcess;

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

/**
 * A job carrying nothing but the timeout under test, since pendingProcess() reads no other state.
 */
function exposedRunWorkflow(int $timeoutSeconds): ExposedRunWorkflow
{
    return new ExposedRunWorkflow(
        timestamp: 0,
        projectUuid: 'project-uuid',
        workspacePath: '/tmp/repo-feature',
        workflowName: 'up',
        stepHashes: [],
        timeoutSeconds: $timeoutSeconds,
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
}
