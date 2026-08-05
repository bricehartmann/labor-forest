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
use App\Events\WorkflowStepStarted;
use App\Services\ProjectsService;
use App\Services\VariableReplacementService;
use App\Services\WorkflowService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

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

        $projectService->updateProjectWorkspaceStatus($workspaceData->path, WorkspaceStatus::CHANGING);
        broadcast(new ProjectDataUpdated($projectData->uuid));

        $logPath = implode(DIRECTORY_SEPARATOR, [
            $workspaceData->path,
            Directory::BASE->value,
            Directory::IGNORED->value,
            Directory::LOGS->value,
        ]);

        if (! File::exists($logPath)) {
            File::makeDirectory($logPath);
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

        $replacementService = app(VariableReplacementService::class);

        $allSuccessful = true;

        foreach ($workflowData->steps as $index => $step) {
            $stepHash = $step->hash((string) $index);

            broadcast(new WorkflowStepStarted(
                projectUuid: $projectData->uuid,
                workspaceSlugKebab: $workspaceData->slugKebab(),
                workflowName: $this->workflowName,
                stepHash: $stepHash,
            ));

            $skipReason = null;

            if (! in_array($stepHash, $this->stepHashes)) {
                $skipReason = WorkflowStepSkipReason::NOT_SELECTED;
            }

            $env = [];

            if ($step->env) {
                foreach ($step->env as $envKey => $envValue) {
                    $env[$envKey] = $replacementService->replace($projectData, $workspaceData, $envValue);
                }
            }

            if (! $skipReason && $step->condition) {
                $conditionProcess = Process::fromShellCommandline(
                    command: $replacementService->replace(
                        projectData: $projectData,
                        workspaceData: $workspaceData,
                        content: $step->condition,
                    ),
                    cwd: $workspaceData->path,
                    env: $env,
                );
                $conditionProcess->run();

                if (! $conditionProcess->isSuccessful()) {
                    $skipReason = WorkflowStepSkipReason::CONDITION_FAILED;
                }
            }

            if (! $skipReason && $step->type === WorkflowStepType::SHELL) {
                $output = '';

                $runProcess = Process::fromShellCommandline(
                    command: $replacementService->replace(
                        projectData: $projectData,
                        workspaceData: $workspaceData,
                        content: $step->run,
                    ),
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

                    // no need to broadcast WorkflowStepFinished because it will be picked up on data refresh from WorkflowFinished event

                    break;
                } else {
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

                if (! File::exists($envPath)) {
                    File::put($envPath, '');
                }

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

                $logData->appendToSteps(new WorkflowRunLogStepData(
                    name: $step->name,
                    type: $step->type,
                    exitCode: 0,
                    output: implode(PHP_EOL, $written),
                    skip_reason: $skipReason,
                    env: $step->env,
                    condition: $step->condition,
                    run: null,
                    map: $step->map,
                ));
                $this->writeLog($logPath, $logData);

                broadcast(new WorkflowStepFinished(
                    projectUuid: $projectData->uuid,
                    workspaceSlugKebab: $workspaceData->slugKebab(),
                    workflowName: $this->workflowName,
                    stepHash: $stepHash,
                    status: WorkflowStatus::SUCCESS->value,
                ));
            } elseif (! $skipReason && $step->type === WorkflowStepType::WORKFLOW) {
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
            }
        }

        $projectService->updateProjectWorkspaceStatus($workspaceData->path, match (true) {
            ! $allSuccessful => WorkspaceStatus::ERROR,
            $this->workflowName === WorkflowKnownName::DOWN->value => WorkspaceStatus::SUSPENDED,
            $this->workflowName === WorkflowKnownName::UP->value => WorkspaceStatus::READY,
            default => $currentStatus,
        });

        if ($allSuccessful) {
            $logData->status = WorkflowStatus::SUCCESS;
        } else {
            $logData->status = WorkflowStatus::FAILED;
        }

        $this->writeLog($logPath, $logData);

        broadcast(new WorkflowFinished(
            projectUuid: $projectData->uuid,
            workspaceSlugKebab: $workspaceData->slugKebab(),
            workflowName: $this->workflowName,
            status: $allSuccessful ? WorkflowStatus::SUCCESS->value : WorkflowStatus::FAILED->value,
        ));
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

    public function fail($exception = null)
    {
        $projectService = app(ProjectsService::class);
        $projectData = $projectService->loadProject($this->projectUuid);
        $projectService->updateProjectWorkspaceStatus($projectData->path, WorkspaceStatus::ERROR);
    }
}
