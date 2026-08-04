<?php

namespace App\Jobs;

use App\Data\WorkflowRunLogData;
use App\Data\WorkflowRunLogStepData;
use App\Enums\Directory;
use App\Enums\FileExtension;
use App\Enums\WorkflowKnownName;
use App\Enums\WorkflowStepSkipReason;
use App\Enums\WorkflowStepType;
use App\Enums\WorkspaceStatus;
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
    public function __construct(public string $projectUuid, public string $workspacePath, public string $workflowName, public array $stepHashes, public ?string $parent = null)
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
        $currentStatus = $projectService->loadProjectWorkspaceStatus($projectData->path);
        $projectService->updateProjectWorkspaceStatus($projectData->path, WorkspaceStatus::CHANGING);
        // todo: broadcast event???

        $workspaceData = $projectService->loadProjectWorkspace($this->workspacePath);
        $workflowPath = implode(DIRECTORY_SEPARATOR, [
            $workspaceData->path,
            Directory::BASE->value,
            Directory::WORKFLOWS->value,
            $this->workflowName.'.'.FileExtension::YAML->value,
        ]);

        $workflowData = app(WorkflowService::class)->loadWorkflow($workflowPath);

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

        $logFileName = $now->format('Ymd\THis\Z').$workspaceData->slugKebab().'_'.Str::slug($this->workflowName).'.yaml';
        $logPath .= DIRECTORY_SEPARATOR.$logFileName;
        $logData = new WorkflowRunLogData(
            parent: $this->parent,
            timestamp: $now->timestamp,
            steps: collect(),
        );

        $replacementService = app(VariableReplacementService::class);

        $allSuccessful = true;

        foreach ($workflowData->steps as $step) {
            // todo: broadcast event??? step started

            $skipReason = null;

            if (! in_array($step->hash(), $this->stepHashes)) {
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
                $runProcess->run(function ($type, $buffer) use (&$output): void {
                    if ($type === Process::ERR) {
                        $output .= 'ERROR: '.$buffer;
                    } else {
                        $output .= $buffer;
                    }

                    // todo: broadcast event???
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
                    // todo: broadcast event???
                    break;
                } else {
                    // todo: broadcast event???
                }
            } elseif (! $skipReason && $step->type === WorkflowStepType::UPDATE_ENV) {
                // todo: update env based on map
            } elseif (! $skipReason && $step->type === WorkflowStepType::WORKFLOW) {
                // todo: dispatch workflow ???
            }
        }

        $projectService->updateProjectWorkspaceStatus($projectData->path, match (true) {
            ! $allSuccessful => WorkspaceStatus::ERROR,
            $this->workflowName === WorkflowKnownName::DOWN->value => WorkspaceStatus::SUSPENDED,
            $this->workflowName === WorkflowKnownName::UP->value => WorkspaceStatus::READY,
            default => $currentStatus,
        });
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
