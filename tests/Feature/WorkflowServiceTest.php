<?php

use App\Data\WorkflowData;
use App\Data\WorkflowRunLogData;
use App\Data\WorkflowRunLogStepData;
use App\Data\WorkflowStepData;
use App\Data\WorkspaceData;
use App\Enums\GitStatus;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowStepType;
use App\Enums\WorkspaceStatus;
use App\Exceptions\InvalidWorkflowFile;
use App\Exceptions\WorkflowNotRunnable;
use App\Exceptions\WorkspaceNotFound;
use App\Jobs\RunWorkflow;
use App\Services\ProjectsService;
use App\Services\WorkflowService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    Storage::fake('user_home');

    $this->workflows = new WorkflowService;
    $this->workspacePath = '/tmp/repo-feature';
    $this->logsPath = '/tmp/repo-feature/.laborforest/ignored/logs';
    $this->workspace = workflowWorkspaceData('/tmp/repo-feature');
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('ensureLogFilePathDirectoryExists', function () {
    beforeEach(function () {
        $this->logsDirectoryExists = true;
        $this->makeDirectorySucceeds = true;
        $this->makeDirectoryThrows = false;
        $this->madeDirectories = [];

        File::shouldReceive('exists')->andReturnUsing(fn (string $path) => $this->logsDirectoryExists);
        File::shouldReceive('makeDirectory')->andReturnUsing(function (...$arguments) {
            if ($this->makeDirectoryThrows) {
                throw new ErrorException('mkdir(): Permission denied');
            }

            $this->madeDirectories[] = $arguments;

            return $this->makeDirectorySucceeds;
        });
    });

    it('returns the logs directory without creating it when it already exists', function () {
        expect($this->workflows->ensureLogFilePathDirectoryExists($this->workspacePath))->toBe($this->logsPath)
            ->and($this->madeDirectories)->toBe([]);
    });

    it('creates the logs directory when it is missing, without creating its parents', function () {
        $this->logsDirectoryExists = false;

        expect($this->workflows->ensureLogFilePathDirectoryExists($this->workspacePath))->toBe($this->logsPath)
            ->and($this->madeDirectories)->toBe([[$this->logsPath]]);
    });

    it('still returns the path when the directory could not be created', function () {
        $this->logsDirectoryExists = false;
        $this->makeDirectorySucceeds = false;

        expect($this->workflows->ensureLogFilePathDirectoryExists($this->workspacePath))->toBe($this->logsPath)
            ->and($this->madeDirectories)->toHaveCount(1);
    });

    it('propagates a filesystem failure without returning a path', function () {
        $this->logsDirectoryExists = false;
        $this->makeDirectoryThrows = true;

        expect(fn () => $this->workflows->ensureLogFilePathDirectoryExists($this->workspacePath))
            ->toThrow(ErrorException::class, 'mkdir(): Permission denied')
            ->and($this->madeDirectories)->toBe([]);
    });
});

describe('writeWorkflowLogData', function () {
    beforeEach(function () {
        $this->putSucceeds = true;
        $this->putThrows = false;
        $this->writes = [];

        File::shouldReceive('put')->andReturnUsing(function (string $path, string $contents) {
            if ($this->putThrows) {
                throw new ErrorException('file_put_contents(): Permission denied');
            }

            $this->writes[] = [$path, $contents];

            return $this->putSucceeds;
        });
    });

    it('dumps the run log to yaml with the resource type first', function () {
        $this->workflows->writeWorkflowLogData($this->logsPath.'/run.yaml', runLogData());

        expect($this->writes)->toHaveCount(1)
            ->and($this->writes[0][0])->toBe($this->logsPath.'/run.yaml')
            ->and($this->writes[0][1])->toStartWith('resource_type: run_log')
            ->and(Yaml::parse($this->writes[0][1]))->toMatchArray([
                'resource_type' => 'run_log',
                'id' => '20240101T000000Z_repo-feature_up',
                'name' => 'up',
                'parent' => null,
                'timestamp' => 1704067200,
                'status' => 'pending',
                'exception' => null,
            ]);
    });

    it('omits the null keys of a pending step', function () {
        $this->workflows->writeWorkflowLogData($this->logsPath.'/run.yaml', runLogData());

        expect(Yaml::parse($this->writes[0][1])['steps'][0])->toBe([
            'name' => 'Install dependencies',
            'type' => 'shell',
            'output' => '',
            'run' => 'composer install',
            'hash' => 'aaa111',
        ]);
    });

    it('reports nothing when the write fails', function () {
        $this->putSucceeds = false;

        expect($this->workflows->writeWorkflowLogData($this->logsPath.'/run.yaml', runLogData()))->toBeNull()
            ->and($this->writes)->toHaveCount(1);
    });

    it('propagates a filesystem failure', function () {
        $this->putThrows = true;

        expect(fn () => $this->workflows->writeWorkflowLogData($this->logsPath.'/run.yaml', runLogData()))
            ->toThrow(ErrorException::class, 'file_put_contents(): Permission denied')
            ->and($this->writes)->toBe([]);
    });
});

describe('workflowRunLogData', function () {
    it('seeds a pending entry for every step of the workflow', function () {
        $runLog = $this->workflows->workflowRunLogData(
            timestamp: 1704067200,
            workspaceData: $this->workspace,
            workflowName: 'up',
            parentLogId: null,
            status: WorkflowStatus::PENDING,
            workflowSteps: collect([shellStep('Install dependencies'), shellStep('Build assets')]),
        );

        expect($runLog)->toBeInstanceOf(WorkflowRunLogData::class)
            ->and($runLog->id)->toBe('20240101T000000Z_repo-feature_up')
            ->and($runLog->name)->toBe('up')
            ->and($runLog->parent)->toBeNull()
            ->and($runLog->timestamp)->toBe(1704067200)
            ->and($runLog->status)->toBe(WorkflowStatus::PENDING)
            ->and($runLog->exception)->toBeNull()
            ->and($runLog->steps)->toHaveCount(2)
            ->and($runLog->steps->first())->toBeInstanceOf(WorkflowRunLogStepData::class)
            ->and($runLog->steps->pluck('name')->all())->toBe(['Install dependencies', 'Build assets'])
            ->and($runLog->steps->every(fn (WorkflowRunLogStepData $step) => $step->isPending()))->toBeTrue();
    });

    it('records the parent run log id of a chained workflow', function () {
        $runLog = $this->workflows->workflowRunLogData(
            timestamp: 1704067200,
            workspaceData: $this->workspace,
            workflowName: 'child',
            parentLogId: '20240101T000000Z_repo-feature_up',
            status: WorkflowStatus::RUNNING,
            workflowSteps: collect([shellStep('Install dependencies')]),
        );

        expect($runLog->parent)->toBe('20240101T000000Z_repo-feature_up')
            ->and($runLog->status)->toBe(WorkflowStatus::RUNNING)
            ->and($runLog->id)->toBe('20240101T000000Z_repo-feature_child');
    });

    it('hashes each step by its position after the keys are discarded', function () {
        $steps = collect(['second' => shellStep('Build assets'), 'first' => shellStep('Install dependencies')]);

        $runLog = $this->workflows->workflowRunLogData(
            timestamp: 1704067200,
            workspaceData: $this->workspace,
            workflowName: 'up',
            parentLogId: null,
            status: WorkflowStatus::PENDING,
            workflowSteps: $steps,
        );

        expect($runLog->steps->keys()->all())->toBe([0, 1])
            ->and($runLog->steps->pluck('name')->all())->toBe(['Build assets', 'Install dependencies'])
            ->and($runLog->steps->pluck('hash')->all())->toBe([
                $steps->get('second')->hash('0'),
                $steps->get('first')->hash('1'),
            ]);
    });

    it('builds an empty run log for a workflow with no steps', function () {
        $runLog = $this->workflows->workflowRunLogData(
            timestamp: 1704067200,
            workspaceData: $this->workspace,
            workflowName: 'up',
            parentLogId: null,
            status: WorkflowStatus::PENDING,
            workflowSteps: collect(),
        );

        expect($runLog->steps)->toBeEmpty()
            ->and($runLog->id)->toBe('20240101T000000Z_repo-feature_up');
    });

    it('throws when the collection holds something other than step data', function () {
        expect(fn () => $this->workflows->workflowRunLogData(
            timestamp: 1704067200,
            workspaceData: $this->workspace,
            workflowName: 'up',
            parentLogId: null,
            status: WorkflowStatus::PENDING,
            workflowSteps: collect(['not a step']),
        ))->toThrow(TypeError::class);
    });
});

describe('runLogId', function () {
    it('builds an id from the utc timestamp, workspace and workflow name', function (int $timestamp, string $workflowName, string $expected) {
        expect($this->workflows->runLogId($this->workspace, $timestamp, $workflowName))->toBe($expected);
    })->with([
        'epoch' => [0, 'up', '19700101T000000Z_repo-feature_up'],
        'midnight utc' => [1704067200, 'up', '20240101T000000Z_repo-feature_up'],
        'mid day' => [1704112496, 'up', '20240101T123456Z_repo-feature_up'],
        'name with spaces and case' => [1704067200, 'Deploy To Prod', '20240101T000000Z_repo-feature_deploy-to-prod'],
        'name with punctuation' => [1704067200, 'up/down!', '20240101T000000Z_repo-feature_updown'],
    ]);

    it('slugs the workspace directory name rather than its full path', function () {
        expect($this->workflows->runLogId(workflowWorkspaceData('/tmp/code/Some Repo_Feature'), 1704067200, 'up'))
            ->toBe('20240101T000000Z_some-repo-feature_up');
    });

    it('produces an id the log file name pattern rejects when the name has no sluggable characters', function () {
        $id = $this->workflows->runLogId($this->workspace, 1704067200, '!!!');

        expect($id)->toBe('20240101T000000Z_repo-feature_')
            ->and(preg_match('/^\d{8}T\d{6}Z_repo-feature_.+$/', $id))->toBe(0)
            ->and($this->workflows->loadWorkflowLogDatum($this->workspace, $id))->toBeNull();
    });
});

describe('availableLogTimestamp', function () {
    beforeEach(function () {
        $this->existingLogFiles = [];
        $this->checkedPaths = [];
        $this->isFileThrows = false;

        File::shouldReceive('isFile')->andReturnUsing(function (string $path) {
            if ($this->isFileThrows) {
                throw new ErrorException('is_file(): Permission denied');
            }

            $this->checkedPaths[] = $path;

            return in_array($path, $this->existingLogFiles, true);
        });
    });

    it('returns the timestamp unchanged when no run log claims it', function () {
        expect($this->workflows->availableLogTimestamp($this->workspace, 'up', 1704067200))->toBe(1704067200)
            ->and($this->checkedPaths)->toBe([$this->logsPath.'/20240101T000000Z_repo-feature_up.yaml']);
    });

    it('moves past the run logs already claiming the timestamp', function () {
        $this->existingLogFiles = [
            $this->logsPath.'/20240101T000000Z_repo-feature_up.yaml',
            $this->logsPath.'/20240101T000001Z_repo-feature_up.yaml',
        ];

        expect($this->workflows->availableLogTimestamp($this->workspace, 'up', 1704067200))->toBe(1704067202)
            ->and($this->checkedPaths)->toHaveCount(3);
    });

    it('ignores a run log of a different workflow at the same timestamp', function () {
        $this->existingLogFiles = [$this->logsPath.'/20240101T000000Z_repo-feature_down.yaml'];

        expect($this->workflows->availableLogTimestamp($this->workspace, 'up', 1704067200))->toBe(1704067200);
    });

    it('ignores a run log belonging to a different workspace', function () {
        $this->existingLogFiles = ['/tmp/repo-other/.laborforest/ignored/logs/20240101T000000Z_repo-other_up.yaml'];

        expect($this->workflows->availableLogTimestamp($this->workspace, 'up', 1704067200))->toBe(1704067200);
    });

    it('propagates a filesystem failure without probing any further timestamp', function () {
        $this->isFileThrows = true;

        expect(fn () => $this->workflows->availableLogTimestamp($this->workspace, 'up', 1704067200))
            ->toThrow(ErrorException::class, 'is_file(): Permission denied')
            ->and($this->checkedPaths)->toBe([]);
    });
});

describe('dispatchWorkflow', function () {
    beforeEach(function () {
        Queue::fake();
        Carbon::setTestNow('2024-01-01 00:00:00');

        $this->fixturePath = fixtureWorkspacePath('repo-feature');
        $this->fixtureLogsPath = $this->fixturePath.'/.laborforest/ignored/logs';
        $this->projects = new FakeProjectsService;
        $this->projects->workspaceData = workflowWorkspaceData($this->fixturePath, status: WorkspaceStatus::SUSPENDED);
        $this->instance(ProjectsService::class, $this->projects);

        $this->logsDirectoryExists = true;
        $this->madeDirectories = [];
        $this->writes = [];

        File::shouldReceive('isFile')->andReturnFalse();
        File::shouldReceive('exists')->andReturnUsing(fn () => $this->logsDirectoryExists);
        File::shouldReceive('makeDirectory')->andReturnUsing(function (...$arguments) {
            $this->madeDirectories[] = $arguments;

            return true;
        });
        File::shouldReceive('put')->andReturnUsing(function (string $path, string $contents) {
            $this->writes[] = [$path, $contents];

            return true;
        });
    });

    it('writes the run log and queues the job, returning the run log id', function () {
        $id = $this->workflows->dispatchWorkflow('project-uuid', $this->fixturePath, 'up', ['aaa111'], null, 600);

        expect($id)->toBe('20240101T000000Z_repo-feature_up')
            ->and($this->projects->statusUpdates)->toBe([[$this->fixturePath, WorkspaceStatus::PENDING]])
            ->and($this->writes)->toHaveCount(1)
            ->and($this->writes[0][0])->toBe($this->fixtureLogsPath.'/20240101T000000Z_repo-feature_up.yaml')
            ->and($this->writes[0][1])->toStartWith('resource_type: run_log')
            ->and(Yaml::parse($this->writes[0][1]))->toMatchArray([
                'id' => '20240101T000000Z_repo-feature_up',
                'name' => 'up',
                'parent' => null,
                'timestamp' => 1704067200,
                'status' => 'pending',
            ])
            ->and($this->madeDirectories)->toBe([]);
    });

    it('creates the logs directory before writing the run log when it is missing', function () {
        $this->logsDirectoryExists = false;

        $this->workflows->dispatchWorkflow('project-uuid', $this->fixturePath, 'up', [], null, 600);

        expect($this->madeDirectories)->toBe([[$this->fixtureLogsPath]])
            ->and($this->writes)->toHaveCount(1)
            ->and($this->writes[0][0])->toBe($this->fixtureLogsPath.'/20240101T000000Z_repo-feature_up.yaml');
    });

    it('seeds the run log with a pending entry for every step of the workflow file', function () {
        $this->workflows->dispatchWorkflow('project-uuid', $this->fixturePath, 'up', [], null, 600);

        expect(collect(Yaml::parse($this->writes[0][1])['steps'])->pluck('name')->all())
            ->toBe(['Copy .env file', 'Install dependencies']);
    });

    it('pushes the job with the arguments the run was created from', function () {
        $this->workflows->dispatchWorkflow('project-uuid', $this->fixturePath, 'up', ['aaa111', 'bbb222'], 'parent-log-id', 45);

        Queue::assertPushed(RunWorkflow::class, 1);

        $job = Queue::pushed(RunWorkflow::class)->sole();

        expect($job->timestamp)->toBe(1704067200)
            ->and($job->projectUuid)->toBe('project-uuid')
            ->and($job->workspacePath)->toBe($this->fixturePath)
            ->and($job->workflowName)->toBe('up')
            ->and($job->stepHashes)->toBe(['aaa111', 'bbb222'])
            ->and($job->parent)->toBe('parent-log-id')
            ->and($job->timeoutSeconds)->toBe(45)
            ->and($job->ancestorWorkflowNames)->toBe([])
            ->and($job->statusBeforeRun)->toBe(WorkspaceStatus::SUSPENDED);
    });

    it('hands the job the status the run was dispatched from, not the pending one it writes', function () {
        $this->workflows->dispatchWorkflow('project-uuid', $this->fixturePath, 'up', [], null, 600);

        expect($this->projects->statusUpdates)->toBe([[$this->fixturePath, WorkspaceStatus::PENDING]])
            ->and(Queue::pushed(RunWorkflow::class)->sole()->statusBeforeRun)->toBe(WorkspaceStatus::SUSPENDED);
    });

    it('throws before touching the workspace when the workspace is unknown', function () {
        $this->projects->workspaceData = null;

        expect(fn () => $this->workflows->dispatchWorkflow('project-uuid', '/tmp/repo-gone', 'up', [], null, 600))
            ->toThrow(WorkspaceNotFound::class, "Workspace at path '/tmp/repo-gone' not found.")
            ->and($this->projects->statusUpdates)->toBe([])
            ->and($this->madeDirectories)->toBe([])
            ->and($this->writes)->toBe([]);

        Queue::assertNothingPushed();
    });

    it('throws before touching the workspace when the workflow file is unusable', function () {
        $path = $this->fixturePath.'/.laborforest/workflows/missing.yaml';

        expect(fn () => $this->workflows->dispatchWorkflow('project-uuid', $this->fixturePath, 'missing', [], null, 600))
            ->toThrow(InvalidWorkflowFile::class, 'The workflow file ['.$path.'] is invalid: File "'.$path.'" does not exist.')
            ->and($this->projects->statusUpdates)->toBe([])
            ->and($this->madeDirectories)->toBe([])
            ->and($this->writes)->toBe([]);

        Queue::assertNothingPushed();
    });

    it('throws before touching the workspace when its status is not the one the workflow requires', function () {
        $this->projects->workspaceData = workflowWorkspaceData($this->fixturePath, status: WorkspaceStatus::READY);

        expect(fn () => $this->workflows->dispatchWorkflow('project-uuid', $this->fixturePath, 'up', [], null, 600))
            ->toThrow(WorkflowNotRunnable::class, 'Workflow [up] requires the workspace to be suspended, but it is ready.')
            ->and($this->projects->statusUpdates)->toBe([])
            ->and($this->writes)->toBe([]);

        Queue::assertNothingPushed();
    });

    it('throws when the workspace is already working, even for a workflow requiring no status', function () {
        $this->projects->workspaceData = workflowWorkspaceData($this->fixturePath, status: WorkspaceStatus::WORKING);

        expect(fn () => $this->workflows->dispatchWorkflow('project-uuid', $this->fixturePath, 'empty-steps', [], null, 600))
            ->toThrow(WorkflowNotRunnable::class, 'Workflow [empty-steps] cannot run while the workspace is working.')
            ->and($this->projects->statusUpdates)->toBe([])
            ->and($this->writes)->toBe([]);

        Queue::assertNothingPushed();
    });
});

describe('ensureWorkspaceCanRunWorkflow', function () {
    it('passes a workflow requiring no particular status', function () {
        $this->workflows->ensureWorkspaceCanRunWorkflow(
            workflowWorkspaceData($this->workspacePath, status: WorkspaceStatus::READY),
            'refresh',
            componentWorkflowData(requireStatus: null),
        );
    })->throwsNoExceptions();

    it('passes when the workspace is in the required status', function () {
        $this->workflows->ensureWorkspaceCanRunWorkflow(
            workflowWorkspaceData($this->workspacePath, status: WorkspaceStatus::SUSPENDED),
            'up',
            componentWorkflowData(requireStatus: WorkspaceStatus::SUSPENDED),
        );
    })->throwsNoExceptions();

    it('throws when the workspace is in a different status than the one required', function () {
        expect(fn () => $this->workflows->ensureWorkspaceCanRunWorkflow(
            workflowWorkspaceData($this->workspacePath, status: WorkspaceStatus::READY),
            'up',
            componentWorkflowData(requireStatus: WorkspaceStatus::SUSPENDED),
        ))->toThrow(WorkflowNotRunnable::class, 'Workflow [up] requires the workspace to be suspended, but it is ready.');
    });

    it('throws for a status no workflow may be started from', function (WorkspaceStatus $status) {
        expect(fn () => $this->workflows->ensureWorkspaceCanRunWorkflow(
            workflowWorkspaceData($this->workspacePath, status: $status),
            'up',
            componentWorkflowData(requireStatus: $status),
        ))->toThrow(WorkflowNotRunnable::class, "Workflow [up] requires the workspace to be {$status->value}, but it is {$status->value}.");
    })->with([
        WorkspaceStatus::PENDING,
        WorkspaceStatus::WORKING,
        WorkspaceStatus::ERROR,
        WorkspaceStatus::UNKNOWN,
    ]);
});

describe('loadSteps', function () {
    beforeEach(function () {
        $this->fixturePath = fixtureWorkspacePath('repo-feature');
    });

    it('returns the steps of the named workflow file', function () {
        $steps = $this->workflows->loadSteps($this->fixturePath, 'up');

        expect($steps)->toHaveCount(2)
            ->and($steps->first())->toBeInstanceOf(WorkflowStepData::class)
            ->and($steps->pluck('name')->all())->toBe(['Copy .env file', 'Install dependencies'])
            ->and($steps->first()->type)->toBe(WorkflowStepType::SHELL)
            ->and($steps->first()->if)->toBe('test "{{ WORKSPACE_DIR }}" != "{{ PROJECT_PRIMARY_DIR }}"')
            ->and($steps->first()->run)->toBe('cp "{{ PROJECT_PRIMARY_DIR }}/.env" .env');
    });

    it('returns an empty collection for a workflow declaring no steps', function () {
        expect($this->workflows->loadSteps($this->fixturePath, 'empty-steps'))->toBeEmpty();
    });

    it('throws when the workflow file does not exist', function () {
        $path = $this->fixturePath.'/.laborforest/workflows/nope.yaml';

        expect(fn () => $this->workflows->loadSteps($this->fixturePath, 'nope'))
            ->toThrow(InvalidWorkflowFile::class, 'The workflow file ['.$path.'] is invalid: File "'.$path.'" does not exist.');
    });

    it('throws when the workflow file is not parseable yaml', function () {
        $path = $this->fixturePath.'/.laborforest/workflows/broken.yaml';

        expect(fn () => $this->workflows->loadSteps($this->fixturePath, 'broken'))
            ->toThrow(InvalidWorkflowFile::class, 'The workflow file ['.$path.'] is invalid: Malformed inline YAML string at line 3.');
    });
});

describe('loadWorkflows', function () {
    beforeEach(function () {
        $this->fixturePath = fixtureWorkspacePath('repo-feature');
    });

    it('keys the runnable workflows by file name and orders them by sort order', function () {
        $workflows = $this->workflows->loadWorkflows($this->fixturePath);

        expect($workflows->keys()->all())->toBe(['up', 'down'])
            ->and($workflows->get('up'))->toBeInstanceOf(WorkflowData::class)
            ->and($workflows->get('up')->sort_order)->toBe(0)
            ->and($workflows->get('up')->require_status)->toBe(WorkspaceStatus::SUSPENDED)
            ->and($workflows->get('up')->ending_status)->toBe(WorkspaceStatus::READY)
            ->and($workflows->get('up')->steps)->toHaveCount(2)
            ->and($workflows->get('down')->sort_order)->toBe(100)
            ->and($workflows->get('down')->steps)->toHaveCount(1);
    });

    it('skips a workflow file it should not load', function (string $name) {
        expect($this->workflows->loadWorkflows($this->fixturePath)->keys()->all())->not->toContain($name);
    })->with([
        'wrong file extension' => ['notes'],
        'wrong resource type' => ['other'],
        'not parseable yaml' => ['broken'],
        'no steps' => ['empty-steps'],
    ]);

    it('returns an empty collection when the workflows directory does not exist', function () {
        File::partialMock()->shouldReceive('isDirectory')->andReturnFalse();

        expect($this->workflows->loadWorkflows($this->fixturePath))->toBeEmpty();
    });

    it('throws when a workflow file passes the resource type filter but fails validation', function () {
        $path = fixtureWorkspacePath('repo-invalid').'/.laborforest/workflows/bad-schema.yaml';

        expect(fn () => $this->workflows->loadWorkflows(fixtureWorkspacePath('repo-invalid')))
            ->toThrow(InvalidWorkflowFile::class, 'The workflow file ['.$path.'] is invalid: The selected require status is invalid.');
    });
});

describe('loadWorkflowLogData', function () {
    beforeEach(function () {
        $this->logsWorkspace = workflowWorkspaceData(fixtureWorkspacePath('repo-logs'));
    });

    it('returns the run logs newest first, re-indexed from zero', function () {
        $logs = $this->workflows->loadWorkflowLogData($this->logsWorkspace);

        expect($logs)->toHaveCount(2)
            ->and($logs->keys()->all())->toBe([0, 1])
            ->and($logs->first())->toBeInstanceOf(WorkflowRunLogData::class)
            ->and($logs->pluck('id')->all())->toBe([
                '20240102T000000Z_repo-logs_down',
                '20240101T000000Z_repo-logs_up',
            ])
            ->and($logs->pluck('timestamp')->all())->toBe([1704153600, 1704067200])
            ->and($logs->first()->status)->toBe(WorkflowStatus::FAILED)
            ->and($logs->first()->parent)->toBe('20240101T000000Z_repo-logs_up')
            ->and($logs->first()->steps->first()->exitCode)->toBe(1);
    });

    it('drops a log file it cannot turn into a run log', function (string $id) {
        expect($this->workflows->loadWorkflowLogData($this->logsWorkspace)->pluck('id')->all())->not->toContain($id);
    })->with([
        'name outside the id pattern' => ['notalog'],
        'not parseable yaml' => ['20240103T000000Z_repo-logs_broken'],
        'wrong resource type' => ['20240104T000000Z_repo-logs_wrong'],
        'missing required keys' => ['20240105T000000Z_repo-logs_partial'],
        'wrong file extension' => ['20240106T000000Z_repo-logs_note'],
        'another workspace slug' => ['20240107T000000Z_repo-other_up'],
    ]);

    it('returns an empty collection when the logs directory does not exist', function () {
        File::partialMock()->shouldReceive('isDirectory')->andReturnFalse();

        expect($this->workflows->loadWorkflowLogData($this->logsWorkspace))->toBeEmpty();
    });

    it('returns an empty collection when every log file is unusable', function () {
        expect($this->workflows->loadWorkflowLogData(workflowWorkspaceData(fixtureWorkspacePath('repo-badlogs'))))->toBeEmpty();
    });
});

describe('loadWorkflowLogDatum', function () {
    beforeEach(function () {
        $this->logsWorkspace = workflowWorkspaceData(fixtureWorkspacePath('repo-logs'));
    });

    it('reads a single run log by its id', function () {
        $log = $this->workflows->loadWorkflowLogDatum($this->logsWorkspace, '20240101T000000Z_repo-logs_up');

        expect($log)->toBeInstanceOf(WorkflowRunLogData::class)
            ->and($log->id)->toBe('20240101T000000Z_repo-logs_up')
            ->and($log->name)->toBe('up')
            ->and($log->status)->toBe(WorkflowStatus::SUCCESS)
            ->and($log->steps)->toHaveCount(1)
            ->and($log->steps->first()->name)->toBe('Copy .env file')
            ->and($log->steps->first()->output)->toBe("copied\n");
    });

    it('returns null for an id the log file name pattern rejects', function (string $id) {
        expect($this->workflows->loadWorkflowLogDatum($this->logsWorkspace, $id))->toBeNull();
    })->with([
        'path traversal' => ['../../../../etc/passwd'],
        'traversal after a valid prefix' => ['20240101T000000Z_repo-logs_../../../etc/passwd'],
        'another workspace slug' => ['20240107T000000Z_repo-other_up'],
        'no timestamp' => ['notalog'],
        'empty workflow slug' => ['20240101T000000Z_repo-logs_'],
        'empty id' => [''],
    ]);

    it('returns null when no log file exists for the id', function () {
        expect($this->workflows->loadWorkflowLogDatum($this->logsWorkspace, '20240108T000000Z_repo-logs_up'))->toBeNull();
    });

    it('returns null when the log file is unusable', function (string $id) {
        expect($this->workflows->loadWorkflowLogDatum($this->logsWorkspace, $id))->toBeNull();
    })->with([
        'not parseable yaml' => ['20240103T000000Z_repo-logs_broken'],
        'wrong resource type' => ['20240104T000000Z_repo-logs_wrong'],
        'missing required keys' => ['20240105T000000Z_repo-logs_partial'],
        'wrong file extension' => ['20240106T000000Z_repo-logs_note'],
    ]);
});

describe('loadWorkflow', function () {
    it('hydrates a workflow from its file', function () {
        $workflow = $this->workflows->loadWorkflow(fixtureWorkflowPath('valid'));

        expect($workflow)->toBeInstanceOf(WorkflowData::class)
            ->and($workflow->require_status)->toBe(WorkspaceStatus::SUSPENDED)
            ->and($workflow->ending_status)->toBe(WorkspaceStatus::READY)
            ->and($workflow->sort_order)->toBe(7)
            ->and($workflow->steps)->toHaveCount(2)
            ->and($workflow->steps->first()->type)->toBe(WorkflowStepType::UPDATE_ENV)
            ->and($workflow->steps->first()->map)->toBe(['APP_URL' => 'http://{{ WORKSPACE_SLUG_KEBAB }}.test'])
            ->and($workflow->steps->last()->type)->toBe(WorkflowStepType::WORKFLOW)
            ->and($workflow->steps->last()->unless)->toBe('test -f .skip')
            ->and($workflow->steps->last()->run)->toBe('up');
    });

    it('throws when the file does not exist', function () {
        $path = fixtureWorkflowPath('missing');

        expect(fn () => $this->workflows->loadWorkflow($path))
            ->toThrow(InvalidWorkflowFile::class, 'The workflow file ['.$path.'] is invalid: File "'.$path.'" does not exist.');
    });

    it('throws when the file is not parseable yaml', function () {
        $path = fixtureWorkflowPath('unparseable');

        expect(fn () => $this->workflows->loadWorkflow($path))
            ->toThrow(InvalidWorkflowFile::class, 'The workflow file ['.$path.'] is invalid: Malformed inline YAML string at line 3.');
    });

    it('throws when the file parses to something other than a mapping', function () {
        $path = fixtureWorkflowPath('scalar');

        expect(fn () => $this->workflows->loadWorkflow($path))
            ->toThrow(InvalidWorkflowFile::class, 'The workflow file ['.$path.'] is invalid: Expected a mapping, found int.');
    });

    it('treats an empty file as an empty mapping and fails validation', function () {
        $path = fixtureWorkflowPath('empty');

        expect(fn () => $this->workflows->loadWorkflow($path))
            ->toThrow(InvalidWorkflowFile::class, 'The workflow file ['.$path.'] is invalid: The sort order field is required. The steps field must be present.');
    });

    it('reports every validation problem joined by a space', function () {
        try {
            $this->workflows->loadWorkflow(fixtureWorkflowPath('invalid'));
        } catch (InvalidWorkflowFile $e) {
            expect($e->path)->toBe(fixtureWorkflowPath('invalid'))
                ->and($e->problems)->toBe([
                    'The selected require status is invalid.',
                    'The selected ending status is invalid.',
                ])
                ->and($e->messagesAsString())->toBe('The selected require status is invalid. The selected ending status is invalid.');

            return;
        }

        $this->fail('Expected an InvalidWorkflowFile exception.');
    });
});

/**
 * Absolute path to a committed, read-only fixture workspace directory.
 */
function fixtureWorkspacePath(string $name): string
{
    return base_path('tests/Fixtures/workspaces/'.$name);
}

/**
 * Absolute path to a committed, read-only fixture workflow file.
 */
function fixtureWorkflowPath(string $name): string
{
    return base_path('tests/Fixtures/workflows/'.$name.'.yaml');
}

/**
 * Build a workspace whose slug is derived from the last segment of the given path.
 */
function workflowWorkspaceData(
    string $path,
    bool $isPrimary = false,
    string $branch = 'feature',
    WorkspaceStatus $status = WorkspaceStatus::READY,
    GitStatus $gitStatus = GitStatus::CLEAN,
): WorkspaceData {
    return new WorkspaceData(
        is_primary: $isPrimary,
        path: $path,
        branch: $branch,
        status: $status,
        git_status: $gitStatus,
    );
}

/**
 * Build a workflow step of the shell type.
 */
function shellStep(string $name, string $run = 'composer install'): WorkflowStepData
{
    return new WorkflowStepData(name: $name, type: WorkflowStepType::SHELL, run: $run);
}

/**
 * Build a run log holding a single pending step.
 */
function runLogData(): WorkflowRunLogData
{
    return new WorkflowRunLogData(
        id: '20240101T000000Z_repo-feature_up',
        name: 'up',
        parent: null,
        timestamp: 1704067200,
        status: WorkflowStatus::PENDING,
        exception: null,
        steps: collect([WorkflowRunLogStepData::pending(shellStep('Install dependencies'), 'aaa111')]),
    );
}

/**
 * A ProjectsService whose workspace lookup and status writes are held in memory, so no worktree is
 * inspected, no status file is written, and the calls WorkflowService makes can be asserted.
 */
final class FakeProjectsService extends ProjectsService
{
    /**
     * The workspace loadProjectWorkspace() reports, or null to make it report the workspace missing.
     */
    public ?WorkspaceData $workspaceData = null;

    /**
     * Every status write, as a [path, status] pair.
     *
     * @var array<int, array{0: string, 1: WorkspaceStatus}>
     */
    public array $statusUpdates = [];

    public function loadProjectWorkspace(string $workspacePath): WorkspaceData
    {
        if (! $this->workspaceData instanceof WorkspaceData) {
            throw new WorkspaceNotFound($workspacePath);
        }

        return $this->workspaceData;
    }

    public function updateProjectWorkspaceStatus(string $path, WorkspaceStatus $workspaceStatus): void
    {
        $this->statusUpdates[] = [$path, $workspaceStatus];
    }
}
