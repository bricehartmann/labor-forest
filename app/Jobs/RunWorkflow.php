<?php

namespace App\Jobs;

use App\Data\ProjectData;
use App\Data\WorkflowData;
use App\Data\WorkflowRunLogData;
use App\Data\WorkflowRunLogStepData;
use App\Data\WorkspaceData;
use App\Enums\Directory;
use App\Enums\File as FileName;
use App\Enums\FileExtension;
use App\Enums\WorkflowKnownName;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowStepSkipReason;
use App\Enums\WorkflowStepType;
use App\Enums\WorkspaceStatus;
use App\Events\ProjectDataUpdated;
use App\Events\WorkflowFinished;
use App\Events\WorkflowStarted;
use App\Events\WorkflowStepFinished;
use App\Events\WorkflowStepOutputUpdated;
use App\Events\WorkflowStepSkipped;
use App\Events\WorkflowStepStarted;
use App\Services\ProjectsService;
use App\Services\VariableReplacementService;
use App\Services\WorkflowService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class RunWorkflow implements ShouldQueue
{
    use Queueable;

    public ?string $logFilePath = null;

    public ?WorkflowRunLogData $workflowRunLogData = null;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $timestamp, public string $projectUuid, public string $workspacePath, public string $workflowName, public array $stepHashes, public ?string $parent = null, public int $timeout = 0)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('workflow: job started', $this->logContext([
            'selected_step_count' => count($this->stepHashes),
            'timeout' => $this->timeout,
        ]));

        $projectService = app(ProjectsService::class);
        $projectData = $projectService->loadProject($this->projectUuid);

        $workspaceData = $projectService->loadProjectWorkspace($this->workspacePath);
        $currentStatus = $projectService->loadProjectWorkspaceStatus($workspaceData->path);
        $workflowPath = implode(DIRECTORY_SEPARATOR, [
            $workspaceData->path,
            Directory::BASE->value,
            Directory::WORKFLOWS->value,
            $this->workflowName.'.'.FileExtension::YAML->value,
        ]);

        $workflowData = app(WorkflowService::class)->loadWorkflow($workflowPath);

        Log::info('workflow: workflow loaded', $this->logContext([
            'workflow_path' => $workflowPath,
            'step_count' => $workflowData->steps->count(),
            'current_status' => $currentStatus->value,
        ]));

        $projectService->updateProjectWorkspaceStatus($workspaceData->path, WorkspaceStatus::WORKING);
        broadcast(new ProjectDataUpdated($projectData->uuid));

        Log::info('workflow: workspace status set to working', $this->logContext());

        $workflowService = app(WorkflowService::class);

        $this->workflowRunLogData = $workflowService->workflowRunLogData(
            timestamp: $this->timestamp,
            workspaceData: $workspaceData,
            workflowName: $this->workflowName,
            parentWorkflowName: $this->parent,
            status: WorkflowStatus::RUNNING,
        );
        $logFileName = $this->workflowRunLogData->id.'.'.FileExtension::YAML->value;
        $this->logFilePath = $workflowService->ensureLogFilePathDirectoryExists($workspaceData->path).DIRECTORY_SEPARATOR.$logFileName;
        $workflowService->writeWorkflowLogData($this->logFilePath, $this->workflowRunLogData);

        Log::info('workflow: run log initialized', $this->logContext([
            'log_path' => $this->logFilePath,
        ]));

        broadcast(new WorkflowStarted(
            projectUuid: $projectData->uuid,
            workspaceSlugKebab: $workspaceData->slugKebab(),
            workflowName: $this->workflowName,
        ));

        try {
            $allSuccessful = $this->runSteps($workflowData, $projectData, $workspaceData);
        } catch (Throwable $throwable) {
            Log::error('workflow: step threw, aborting workflow', $this->logContext([
                'exception' => $throwable->getMessage(),
            ]));

            $this->workflowRunLogData->exception = $throwable->getMessage();
            $allSuccessful = false;
        }

        if ($allSuccessful) {
            $this->workflowRunLogData->status = WorkflowStatus::SUCCESS;
        } else {
            $this->workflowRunLogData->status = WorkflowStatus::FAILED;
        }

        Log::info('workflow: run finished', $this->logContext([
            'status' => $this->workflowRunLogData->status->value,
            'all_successful' => $allSuccessful,
            'steps_logged' => $this->workflowRunLogData->steps->count(),
        ]));

        $workflowService->writeWorkflowLogData($this->logFilePath, $this->workflowRunLogData);

        broadcast(new WorkflowFinished(
            projectUuid: $projectData->uuid,
            workspaceSlugKebab: $workspaceData->slugKebab(),
            workflowName: $this->workflowName,
            status: $allSuccessful ? WorkflowStatus::SUCCESS->value : WorkflowStatus::FAILED->value,
        ));

        $finalStatus = match (true) {
            ! $allSuccessful => WorkspaceStatus::ERROR,
            $this->workflowName === WorkflowKnownName::DOWN->value => WorkspaceStatus::SUSPENDED,
            $this->workflowName === WorkflowKnownName::UP->value => WorkspaceStatus::READY,
            default => $currentStatus,
        };

        Log::info('workflow: resolving workspace status', $this->logContext([
            'final_status' => $finalStatus->value,
            'all_successful' => $allSuccessful,
        ]));

        $projectService->updateProjectWorkspaceStatus($workspaceData->path, $finalStatus);

        broadcast(new ProjectDataUpdated($projectData->uuid));

        Log::info('workflow: job completed', $this->logContext());
    }

    /**
     * Run every step in the workflow, returning false as soon as one of them fails.
     *
     * @throws Throwable
     */
    protected function runSteps(WorkflowData $workflowData, ProjectData $projectData, WorkspaceData $workspaceData): bool
    {
        $replacementService = app(VariableReplacementService::class);

        $allSuccessful = true;

        foreach ($workflowData->steps as $index => $step) {
            $stepHash = $step->hash((string) $index);
            $stepContext = $this->logContext([
                'step_index' => $index,
                'step_name' => $step->name,
                'step_type' => $step->type->value,
                'step_hash' => $stepHash,
            ]);

            Log::info('workflow: step started', $stepContext);

            broadcast(new WorkflowStepStarted(
                projectUuid: $projectData->uuid,
                workspaceSlugKebab: $workspaceData->slugKebab(),
                workflowName: $this->workflowName,
                stepHash: $stepHash,
            ));

            $skipReason = null;

            if (! in_array($stepHash, $this->stepHashes)) {
                $skipReason = WorkflowStepSkipReason::NOT_SELECTED;

                Log::info('workflow: step skipped', [
                    ...$stepContext,
                    'skip_reason' => $skipReason->value,
                ]);
            }

            $env = [];

            if ($step->env) {
                foreach ($step->env as $envKey => $envValue) {
                    $env[$envKey] = $replacementService->replace($projectData, $workspaceData, $envValue);
                }

                Log::info('workflow: step env resolved', [
                    ...$stepContext,
                    'env_keys' => array_keys($env),
                ]);
            }

            if (! $skipReason && $step->if) {
                $if = $replacementService->replace(
                    projectData: $projectData,
                    workspaceData: $workspaceData,
                    content: $step->if,
                );

                Log::info('workflow: evaluating step if', [
                    ...$stepContext,
                    'if' => $if,
                    'env' => $env,
                ]);

                $ifProcess = $this->evaluateGate($if, $workspaceData->path, $env);

                if (! $ifProcess->isSuccessful()) {
                    $skipReason = WorkflowStepSkipReason::IF_FAILED;

                    Log::info('workflow: step if failed', [
                        ...$stepContext,
                        'exit_code' => $ifProcess->getExitCode(),
                        'error' => $ifProcess->getErrorOutput(),
                    ]);
                } else {
                    Log::info('workflow: step if passed', [
                        ...$stepContext,
                        'exit_code' => $ifProcess->getExitCode(),
                    ]);
                }
            }

            if (! $skipReason && $step->unless) {
                $unless = $replacementService->replace(
                    projectData: $projectData,
                    workspaceData: $workspaceData,
                    content: $step->unless,
                );

                Log::info('workflow: evaluating step unless', [
                    ...$stepContext,
                    'unless' => $unless,
                    'env' => $env,
                ]);

                $unlessProcess = $this->evaluateGate($unless, $workspaceData->path, $env);

                if ($unlessProcess->isSuccessful()) {
                    $skipReason = WorkflowStepSkipReason::UNLESS_MATCHED;

                    Log::info('workflow: step unless matched', [
                        ...$stepContext,
                        'exit_code' => $unlessProcess->getExitCode(),
                    ]);
                } else {
                    Log::info('workflow: step unless did not match', [
                        ...$stepContext,
                        'exit_code' => $unlessProcess->getExitCode(),
                        'error' => $unlessProcess->getErrorOutput(),
                    ]);
                }
            }

            $workflowService = app(WorkflowService::class);

            if (! $skipReason && $step->type === WorkflowStepType::SHELL) {
                $output = '';

                $command = $replacementService->replace(
                    projectData: $projectData,
                    workspaceData: $workspaceData,
                    content: $step->run,
                );

                Log::info('workflow: running shell step', [
                    ...$stepContext,
                    'command' => $command,
                ]);

                $start = now()->timestamp;

                $runProcess = Process::fromShellCommandline(
                    command: $this->strictShellCommand($command),
                    cwd: $workspaceData->path,
                    env: $env,
                );
                $runProcess->setTimeout($this->timeout ?: null);
                $runProcess->run(function ($type, $buffer) use (&$output, $projectData, $workspaceData, $stepHash): void {
                    if ($type === Process::ERR) {
                        $output .= 'ERROR: '.$buffer;
                    } else {
                        $output .= $buffer;
                    }

                    broadcast(new WorkflowStepOutputUpdated(
                        projectUuid: $projectData->uuid,
                        workspaceSlugKebab: $workspaceData->slugKebab(),
                        workflowName: $this->workflowName,
                        stepHash: $stepHash,
                        output: $output,
                    ));
                });

                Log::info('workflow: shell step completed', [
                    ...$stepContext,
                    'exit_code' => $runProcess->getExitCode(),
                    'output_length' => strlen($output),
                    'output_preview' => Str::limit($output, 200),
                ]);

                $this->workflowRunLogData->appendToSteps(new WorkflowRunLogStepData(
                    name: $step->name,
                    type: $step->type,
                    exitCode: $runProcess->getExitCode(),
                    output: $output,
                    skip_reason: $skipReason,
                    env: $step->env,
                    if: $step->if,
                    unless: $step->unless,
                    run: $step->run,
                    map: null,
                    started_timestamp: $start,
                    ended_timestamp: now()->timestamp,
                ));
                $workflowService->writeWorkflowLogData($this->logFilePath, $this->workflowRunLogData);

                if (! $runProcess->isSuccessful()) {
                    $allSuccessful = false;

                    Log::info('workflow: shell step failed, aborting workflow', [
                        ...$stepContext,
                        'exit_code' => $runProcess->getExitCode(),
                    ]);

                    // no need to broadcast WorkflowStepFinished because it will be picked up on data refresh from WorkflowFinished event

                    break;
                } else {
                    Log::info('workflow: step finished', $stepContext);

                    broadcast(new WorkflowStepFinished(
                        projectUuid: $projectData->uuid,
                        workspaceSlugKebab: $workspaceData->slugKebab(),
                        workflowName: $this->workflowName,
                        stepHash: $stepHash,
                        status: WorkflowStatus::SUCCESS->value,
                    ));
                }
            } elseif (! $skipReason && $step->type === WorkflowStepType::UPDATE_ENV) {
                $envPath = $workspaceData->path.DIRECTORY_SEPARATOR.FileName::ENV->value;
                $envFileCreated = ! File::exists($envPath);

                if ($envFileCreated) {
                    File::put($envPath, '');
                }

                Log::info('workflow: updating env file', [
                    ...$stepContext,
                    'env_path' => $envPath,
                    'env_file_created' => $envFileCreated,
                ]);

                $start = now()->timestamp;

                $contents = File::get($envPath);
                $written = [];

                foreach ($step->map ?? [] as $envKey => $envValue) {
                    $value = $this->escapeEnvValue($replacementService->replace(
                        projectData: $projectData,
                        workspaceData: $workspaceData,
                        content: (string) $envValue,
                    ));

                    $contents = $this->setEnvValue($contents, $envKey, $value);
                    $written[] = $envKey.'='.$value;
                }

                File::put($envPath, $contents);

                Log::info('workflow: env file written', [
                    ...$stepContext,
                    'keys_written' => array_keys($step->map ?? []),
                ]);

                $this->workflowRunLogData->appendToSteps(new WorkflowRunLogStepData(
                    name: $step->name,
                    type: $step->type,
                    exitCode: 0,
                    output: '',
                    skip_reason: $skipReason,
                    env: $step->env,
                    if: $step->if,
                    unless: $step->unless,
                    run: null,
                    map: $step->map,
                    started_timestamp: $start,
                    ended_timestamp: now()->timestamp,
                ));
                $workflowService->writeWorkflowLogData($this->logFilePath, $this->workflowRunLogData);

                Log::info('workflow: step finished', $stepContext);

                broadcast(new WorkflowStepFinished(
                    projectUuid: $projectData->uuid,
                    workspaceSlugKebab: $workspaceData->slugKebab(),
                    workflowName: $this->workflowName,
                    stepHash: $stepHash,
                    status: WorkflowStatus::SUCCESS->value,
                ));
            } elseif (! $skipReason && $step->type === WorkflowStepType::WORKFLOW) {
                Log::info('workflow: nested workflow step is not implemented, skipping', $stepContext);

                // todo: dispatch workflow ???

                // todo: broadcast event??? (workflow started/dispatched only)
            } elseif ($skipReason) {
                $this->workflowRunLogData->appendToSteps(new WorkflowRunLogStepData(
                    name: $step->name,
                    type: $step->type,
                    exitCode: null,
                    output: '',
                    skip_reason: $skipReason,
                    env: $step->env,
                    if: $step->if,
                    unless: $step->unless,
                    run: $step->run,
                    map: $step->map,
                ));
                $workflowService->writeWorkflowLogData($this->logFilePath, $this->workflowRunLogData);

                broadcast(new WorkflowStepSkipped(
                    projectUuid: $projectData->uuid,
                    workspaceSlugKebab: $workspaceData->slugKebab(),
                    workflowName: $this->workflowName,
                    stepHash: $stepHash,
                    reason: $skipReason->value,
                ));

                Log::info('workflow: skipped step recorded', [
                    ...$stepContext,
                    'skip_reason' => $skipReason->value,
                ]);
            } else {
                Log::info('workflow: step matched no branch', [
                    ...$stepContext,
                    'skip_reason' => $skipReason?->value,
                ]);
            }
        }

        return $allSuccessful;
    }

    /**
     * Wrap a shell step's command so a failure anywhere within it surfaces as a non-zero exit code.
     *
     * Without this, only the final command in a chain decides the step's fate: `false; echo ok`
     * exits 0. `pipefail` is guarded because it exists in bash-as-sh but not in dash.
     */
    protected function strictShellCommand(string $command): string
    {
        return 'set -eu; set -o pipefail 2>/dev/null || true; '.$command;
    }

    /**
     * Run a step's if or unless command and return the finished process for exit code inspection.
     *
     * @param  array<string, string>  $env
     */
    protected function evaluateGate(string $command, string $cwd, array $env): Process
    {
        $process = Process::fromShellCommandline(
            command: $command,
            cwd: $cwd,
            env: $env,
        );
        $process->setTimeout($this->timeout ?: null);
        $process->run();

        return $process;
    }

    /**
     * Build the run-scoped context shared by every log line this job emits.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function logContext(array $extra = []): array
    {
        return [
            'project_uuid' => $this->projectUuid,
            'workspace_path' => $this->workspacePath,
            'workflow_name' => $this->workflowName,
            'parent' => $this->parent,
            ...$extra,
        ];
    }

    /**
     * Replace the given key's line within the .env contents, appending it when the key is absent.
     */
    protected function setEnvValue(string $contents, string $key, string $value): string
    {
        $line = $key.'='.$value;
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $contents) === 1) {
            return preg_replace_callback($pattern, fn (): string => $line, $contents, 1);
        }

        $contents = rtrim($contents, "\r\n");

        return ($contents === '' ? '' : $contents.PHP_EOL).$line.PHP_EOL;
    }

    /**
     * Quote a value only when its contents would otherwise be misread by a .env parser.
     */
    protected function escapeEnvValue(string $value): string
    {
        if (preg_match('/[\s"\'#=]/', $value) !== 1) {
            return $value;
        }

        return '"'.addcslashes($value, '\\"$').'"';
    }

    public function failed(?Throwable $exception = null): void
    {
        try {
            Log::info('workflow: job failed', $this->logContext([
                'exception' => $exception?->getMessage(),
            ]));

            $projectService = app(ProjectsService::class);
            $projectData = $projectService->loadProject($this->projectUuid);
            $workspaceData = $projectService->loadProjectWorkspace($this->workspacePath);

            broadcast(new WorkflowFinished(
                projectUuid: $projectData->uuid,
                workspaceSlugKebab: $workspaceData->slugKebab(),
                workflowName: $this->workflowName,
                status: WorkflowStatus::FAILED->value,
            ));

            $projectService->updateProjectWorkspaceStatus($workspaceData->path, WorkspaceStatus::ERROR);

            Log::info('workflow: workspace status set to error', $this->logContext([
                'path' => $projectData->path,
            ]));

            broadcast(new ProjectDataUpdated($projectData->uuid));
        } catch (Throwable $throwable) {
            Log::error('workflow: exception while handling failed job', $this->logContext([
                'job_exception' => $exception?->getMessage(),
                'exception' => $throwable,
            ]));
        }
    }
}
