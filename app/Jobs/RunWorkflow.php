<?php

namespace App\Jobs;

use App\Data\WorkflowRunLogData;
use App\Data\WorkflowRunLogStepData;
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
use Symfony\Component\Yaml\Yaml;
use Throwable;

class RunWorkflow implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $projectUuid, public string $workspacePath, public string $workflowName, public array $stepHashes, public ?string $parent = null, public int $timeout = 0)
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

        $projectService->updateProjectWorkspaceStatus($workspaceData->path, WorkspaceStatus::CHANGING);
        broadcast(new ProjectDataUpdated($projectData->uuid));

        Log::info('workflow: workspace status set to changing', $this->logContext());

        $logPath = implode(DIRECTORY_SEPARATOR, [
            $workspaceData->path,
            Directory::BASE->value,
            Directory::IGNORED->value,
            Directory::LOGS->value,
        ]);

        if (! File::exists($logPath)) {
            File::makeDirectory($logPath);

            Log::info('workflow: run log directory created', $this->logContext([
                'log_directory' => $logPath,
            ]));
        }

        $now = now();

        $logFileName = $now->format('Ymd\THis\Z').'_'.$workspaceData->slugKebab().'_'.Str::slug($this->workflowName).'.yaml';
        $logPath .= DIRECTORY_SEPARATOR.$logFileName;
        $logData = new WorkflowRunLogData(
            parent: $this->parent,
            timestamp: $now->timestamp,
            status: WorkflowStatus::RUNNING,
            steps: collect(),
        );

        Log::info('workflow: run log initialized', $this->logContext([
            'log_path' => $logPath,
        ]));

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

            if (! $skipReason && $step->condition) {
                $condition = $replacementService->replace(
                    projectData: $projectData,
                    workspaceData: $workspaceData,
                    content: $step->condition,
                );

                Log::info('workflow: evaluating step condition', [
                    ...$stepContext,
                    'condition' => $condition,
                ]);

                $conditionProcess = Process::fromShellCommandline(
                    command: $condition,
                    cwd: $workspaceData->path,
                    env: $env,
                );
                $conditionProcess->run();

                if (! $conditionProcess->isSuccessful()) {
                    $skipReason = WorkflowStepSkipReason::CONDITION_FAILED;

                    Log::info('workflow: step condition failed', [
                        ...$stepContext,
                        'exit_code' => $conditionProcess->getExitCode(),
                    ]);
                } else {
                    Log::info('workflow: step condition passed', [
                        ...$stepContext,
                        'exit_code' => $conditionProcess->getExitCode(),
                    ]);
                }
            }

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

                $runProcess = Process::fromShellCommandline(
                    command: $command,
                    cwd: $workspaceData->path,
                    env: $env,
                );
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

                $logData->appendToSteps(new WorkflowRunLogStepData(
                    name: $step->name,
                    type: $step->type,
                    exitCode: $runProcess->getExitCode(),
                    output: $output,
                    skip_reason: $skipReason,
                    env: $step->env,
                    condition: $step->condition,
                    run: $step->run,
                    map: null,
                ));
                $this->writeLog($logPath, $logData);

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

                $logData->appendToSteps(new WorkflowRunLogStepData(
                    name: $step->name,
                    type: $step->type,
                    exitCode: 0,
                    output: '',
                    skip_reason: $skipReason,
                    env: $step->env,
                    condition: $step->condition,
                    run: null,
                    map: $step->map,
                ));
                $this->writeLog($logPath, $logData);

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
                $logData->appendToSteps(new WorkflowRunLogStepData(
                    name: $step->name,
                    type: $step->type,
                    exitCode: 0,
                    output: '',
                    skip_reason: $skipReason,
                    env: $step->env,
                    condition: $step->condition,
                    run: $step->run,
                    map: $step->map,
                ));
                $this->writeLog($logPath, $logData);

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

        if ($allSuccessful) {
            $logData->status = WorkflowStatus::SUCCESS;
        } else {
            $logData->status = WorkflowStatus::FAILED;
        }

        Log::info('workflow: run finished', $this->logContext([
            'status' => $logData->status->value,
            'all_successful' => $allSuccessful,
            'steps_logged' => $logData->steps->count(),
        ]));

        $this->writeLog($logPath, $logData);

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

    protected function writeLog(string $path, WorkflowRunLogData $logData): void
    {
        File::put($path, Yaml::dump($logData->toArray(), 10));
    }

    public function failed(?Throwable $exception = null): void
    {
        Log::info('workflow: job failed', $this->logContext([
            'exception' => $exception?->getMessage(),
        ]));

        $projectService = app(ProjectsService::class);
        $projectData = $projectService->loadProject($this->projectUuid);
        $workspaceData = $projectService->loadProjectWorkspace($this->workspacePath);
        $projectService->updateProjectWorkspaceStatus($workspaceData->path, WorkspaceStatus::ERROR);

        Log::info('workflow: workspace status set to error', $this->logContext([
            'path' => $projectData->path,
        ]));

        broadcast(new ProjectDataUpdated($projectData->uuid));
    }
}
