<?php

namespace App\Jobs;

use App\Data\ProjectData;
use App\Data\WorkflowData;
use App\Data\WorkflowRunLogData;
use App\Data\WorkflowRunLogStepData;
use App\Data\WorkflowStepData;
use App\Data\WorkspaceData;
use App\Enums\File as FileName;
use App\Enums\FileExtension;
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
use App\Services\ProcessEnvironmentService;
use App\Services\ProjectsService;
use App\Services\VariableReplacementService;
use App\Services\WorkflowService;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

class RunWorkflow implements ShouldQueue
{
    use Queueable;

    /**
     * Minimum seconds between step output broadcasts; process output arrives in far smaller chunks
     * than the UI can usefully render.
     */
    protected const OUTPUT_BROADCAST_INTERVAL_SECONDS = 1.0;

    public ?string $logFilePath = null;

    public ?WorkflowRunLogData $workflowRunLogData = null;

    /**
     * Create a new job instance.
     *
     * The budget is deliberately not named `timeout`: Laravel serializes a job property of that
     * name into the queue payload and the worker then kills the whole run at that many seconds,
     * which would cap a multi-step workflow at the budget meant for each of its steps.
     *
     * @param  ?string  $parent  the run log id of the workflow that started this one, when chained
     * @param  int  $timeoutSeconds  the per-process budget every step and gate gets, where zero means none
     * @param  array<int, string>  $ancestorWorkflowNames  the names of the workflows above this one in the chain
     * @param  ?WorkspaceStatus  $statusBeforeRun  the status the workspace held before the run was dispatched,
     *                                             which a workflow declaring no `ending_status` returns to
     */
    public function __construct(public int $timestamp, public string $projectUuid, public string $workspacePath, public string $workflowName, public array $stepHashes, public ?string $parent = null, public int $timeoutSeconds = 0, public array $ancestorWorkflowNames = [], public ?WorkspaceStatus $statusBeforeRun = null)
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
            'timeout_seconds' => $this->timeoutSeconds,
        ]));

        $projectService = app(ProjectsService::class);
        $projectData = $projectService->loadProject($this->projectUuid);

        $workspaceData = $projectService->loadProjectWorkspace($this->workspacePath);
        // the status on disk is already `pending` by the time this job runs, so the status the run was
        // dispatched from is the only sound answer for a workflow that declares no `ending_status`
        $currentStatus = $this->statusBeforeRun ?? $projectService->loadProjectWorkspaceStatus($workspaceData->path);
        $workflowService = app(WorkflowService::class);
        $workflowPath = $workflowService->workflowPath($workspaceData->path, $this->workflowName);

        $workflowData = $workflowService->loadWorkflow($workflowPath);

        Log::info('workflow: workflow loaded', $this->logContext([
            'workflow_path' => $workflowPath,
            'step_count' => $workflowData->steps->count(),
            'current_status' => $currentStatus->value,
        ]));

        $projectService->updateProjectWorkspaceStatus($workspaceData->path, WorkspaceStatus::WORKING);
        broadcast(new ProjectDataUpdated($projectData->uuid));

        Log::info('workflow: workspace status set to working', $this->logContext());

        $this->workflowRunLogData = $workflowService->workflowRunLogData(
            timestamp: $this->timestamp,
            workspaceData: $workspaceData,
            workflowName: $this->workflowName,
            parentLogId: $this->parent,
            status: WorkflowStatus::RUNNING,
            workflowSteps: $workflowData->steps,
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
            $this->markUnreachedStepsAborted();
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
            logId: $this->workflowRunLogData->id,
        ));

        $finalStatus = $this->resolveFinalStatus($allSuccessful, $workflowData->ending_status, $currentStatus);

        Log::info('workflow: resolving workspace status', $this->logContext([
            'final_status' => $finalStatus->value,
            'all_successful' => $allSuccessful,
        ]));

        $projectService->updateProjectWorkspaceStatus($workspaceData->path, $finalStatus);

        broadcast(new ProjectDataUpdated($projectData->uuid));

        Log::info('workflow: job completed', $this->logContext());
    }

    /**
     * Resolve the status the workspace is left in once the run finishes.
     *
     * A workflow that declares no `ending_status` returns the workspace to where it started rather
     * than leaving it wherever the run happened to put it.
     */
    protected function resolveFinalStatus(bool $allSuccessful, ?WorkspaceStatus $endingStatus, WorkspaceStatus $statusBeforeRun): WorkspaceStatus
    {
        return match (true) {
            ! $allSuccessful => WorkspaceStatus::ERROR,
            default => $endingStatus ?? $statusBeforeRun,
        };
    }

    /**
     * Run every step in the workflow, returning false as soon as one of them fails.
     *
     * @throws Throwable
     */
    protected function runSteps(WorkflowData $workflowData, ProjectData $projectData, WorkspaceData $workspaceData): bool
    {
        $replacementService = app(VariableReplacementService::class);
        $environmentService = app(ProcessEnvironmentService::class);

        $allSuccessful = true;

        foreach ($workflowData->steps as $index => $step) {
            $stepHash = $step->hash((string) $index);
            $logStep = $this->workflowRunLogData->step($index);
            $stepContext = $this->logContext([
                'step_index' => $index,
                'step_name' => $step->name,
                'step_type' => $step->type->value,
                'step_hash' => $stepHash,
            ]);

            Log::info('workflow: step started', $stepContext);

            $skipReason = null;

            if (! in_array($stepHash, $this->stepHashes)) {
                $skipReason = WorkflowStepSkipReason::NOT_SELECTED;

                Log::info('workflow: step skipped', [
                    ...$stepContext,
                    'skip_reason' => $skipReason->value,
                ]);
            }

            $stepEnv = [];

            if ($step->env) {
                foreach ($step->env as $envKey => $envValue) {
                    $stepEnv[$envKey] = $replacementService->replace($projectData, $workspaceData, $envValue);
                }

                Log::info('workflow: step env resolved', [
                    ...$stepContext,
                    'env_keys' => array_keys($stepEnv),
                ]);
            }

            // this application's own environment must not reach the workspace, or a step running an
            // artisan command there would pick up this application's .env instead of the workspace's
            $env = $environmentService->sanitized($stepEnv);

            if (! $skipReason && $step->if) {
                $if = $replacementService->replace(
                    projectData: $projectData,
                    workspaceData: $workspaceData,
                    content: $step->if,
                );

                Log::info('workflow: evaluating step if', [
                    ...$stepContext,
                    'if' => $if,
                    'env' => $stepEnv,
                ]);

                $ifResult = $this->evaluateGate($if, $workspaceData->path, $env);

                if (! $ifResult->successful()) {
                    $skipReason = WorkflowStepSkipReason::IF_FAILED;

                    Log::info('workflow: step if failed', [
                        ...$stepContext,
                        'exit_code' => $ifResult->exitCode(),
                        'error' => $ifResult->errorOutput(),
                    ]);
                } else {
                    Log::info('workflow: step if passed', [
                        ...$stepContext,
                        'exit_code' => $ifResult->exitCode(),
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
                    'env' => $stepEnv,
                ]);

                $unlessResult = $this->evaluateGate($unless, $workspaceData->path, $env);

                if ($unlessResult->successful()) {
                    $skipReason = WorkflowStepSkipReason::UNLESS_MATCHED;

                    Log::info('workflow: step unless matched', [
                        ...$stepContext,
                        'exit_code' => $unlessResult->exitCode(),
                    ]);
                } else {
                    Log::info('workflow: step unless did not match', [
                        ...$stepContext,
                        'exit_code' => $unlessResult->exitCode(),
                        'error' => $unlessResult->errorOutput(),
                    ]);
                }
            }

            if ($skipReason) {
                $logStep->skip_reason = $skipReason;
                $this->flushRunLog();

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

                continue;
            }

            $logStep->started_timestamp = now()->timestamp;
            $this->flushRunLog();

            // broadcast after the flush so a listener re-reading the log sees the step as running
            broadcast(new WorkflowStepStarted(
                projectUuid: $projectData->uuid,
                workspaceSlugKebab: $workspaceData->slugKebab(),
                workflowName: $this->workflowName,
                stepHash: $stepHash,
            ));

            if ($step->type === WorkflowStepType::SHELL) {
                $output = '';
                $lastOutputBroadcastAt = 0.0;

                $command = $replacementService->replace(
                    projectData: $projectData,
                    workspaceData: $workspaceData,
                    content: $step->run,
                );

                Log::info('workflow: running shell step', [
                    ...$stepContext,
                    'command' => $command,
                ]);

                $runResult = $this->pendingProcess($workspaceData->path, $env)->start($this->strictShellCommand($command), function (string $type, string $buffer) use (&$output, &$lastOutputBroadcastAt, $logStep, $projectData, $workspaceData, $stepHash): void {
                    $output .= $buffer;

                    // held in memory only: rewriting the whole log per output chunk would be pathological IO
                    $logStep->output = $output;

                    $now = microtime(true);

                    if ($now - $lastOutputBroadcastAt < self::OUTPUT_BROADCAST_INTERVAL_SECONDS) {
                        return;
                    }

                    $lastOutputBroadcastAt = $now;

                    broadcast(new WorkflowStepOutputUpdated(
                        projectUuid: $projectData->uuid,
                        workspaceSlugKebab: $workspaceData->slugKebab(),
                        workflowName: $this->workflowName,
                        stepHash: $stepHash,
                        output: $output,
                    ));
                })->wait();

                // the throttle above can swallow the final chunks, so always broadcast the whole buffer
                broadcast(new WorkflowStepOutputUpdated(
                    projectUuid: $projectData->uuid,
                    workspaceSlugKebab: $workspaceData->slugKebab(),
                    workflowName: $this->workflowName,
                    stepHash: $stepHash,
                    output: $output,
                ));

                Log::info('workflow: shell step completed', [
                    ...$stepContext,
                    'exit_code' => $runResult->exitCode(),
                    'output_length' => strlen($output),
                    'output_preview' => Str::limit($output, 200),
                ]);

                $logStep->exitCode = $runResult->exitCode();
                $logStep->output = $output;
                $logStep->ended_timestamp = now()->timestamp;
                $this->flushRunLog();

                if (! $runResult->successful()) {
                    $allSuccessful = false;

                    Log::info('workflow: shell step failed, aborting workflow', [
                        ...$stepContext,
                        'exit_code' => $runResult->exitCode(),
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
            } elseif ($step->type === WorkflowStepType::UPDATE_ENV) {
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

                foreach ($step->map ?? [] as $envKey => $envValue) {
                    $value = $this->escapeEnvValue($replacementService->replace(
                        projectData: $projectData,
                        workspaceData: $workspaceData,
                        content: (string) $envValue,
                    ));

                    $contents = $this->setEnvValue($contents, $envKey, $value);
                }

                File::put($envPath, $contents);

                Log::info('workflow: env file written', [
                    ...$stepContext,
                    'keys_written' => array_keys($step->map ?? []),
                ]);

                $logStep->exitCode = 0;
                $logStep->ended_timestamp = now()->timestamp;
                $this->flushRunLog();

                Log::info('workflow: step finished', $stepContext);

                broadcast(new WorkflowStepFinished(
                    projectUuid: $projectData->uuid,
                    workspaceSlugKebab: $workspaceData->slugKebab(),
                    workflowName: $this->workflowName,
                    stepHash: $stepHash,
                    status: WorkflowStatus::SUCCESS->value,
                ));
            } elseif ($step->type === WorkflowStepType::WORKFLOW) {
                $childSuccessful = $this->runChildWorkflow($step, $logStep, $projectData, $workspaceData, $stepContext);

                if (! $childSuccessful) {
                    $allSuccessful = false;

                    Log::info('workflow: child workflow failed, aborting workflow', $stepContext);

                    // no need to broadcast WorkflowStepFinished because it will be picked up on data refresh from WorkflowFinished event

                    break;
                }

                Log::info('workflow: step finished', $stepContext);

                broadcast(new WorkflowStepFinished(
                    projectUuid: $projectData->uuid,
                    workspaceSlugKebab: $workspaceData->slugKebab(),
                    workflowName: $this->workflowName,
                    stepHash: $stepHash,
                    status: WorkflowStatus::SUCCESS->value,
                ));
            }
        }

        return $allSuccessful;
    }

    /**
     * Run the workflow named by a workflow step and report whether it succeeded.
     *
     * The child runs inline rather than being queued so this step only finishes once the child
     * has, letting a chained workflow fail the workflow that started it. It writes its own run
     * log, linked to this one, and is never subjected to the require_status gate that guards a
     * workflow launched by hand.
     *
     * @param  array<string, mixed>  $stepContext
     */
    protected function runChildWorkflow(WorkflowStepData $step, WorkflowRunLogStepData $logStep, ProjectData $projectData, WorkspaceData $workspaceData, array $stepContext): bool
    {
        $workflowService = app(WorkflowService::class);

        $childWorkflowName = trim(app(VariableReplacementService::class)->replace(
            projectData: $projectData,
            workspaceData: $workspaceData,
            content: (string) $step->run,
        ));

        $chain = [...$this->ancestorWorkflowNames, $this->workflowName];

        if (in_array($childWorkflowName, $chain, true)) {
            return $this->failChildWorkflowStep(
                $logStep,
                'Workflow ['.$childWorkflowName.'] is already running in this chain: '.implode(' > ', $chain).'.',
                $stepContext,
            );
        }

        Log::info('workflow: starting child workflow', [
            ...$stepContext,
            'child_workflow_name' => $childWorkflowName,
        ]);

        try {
            $childWorkflowData = $workflowService->loadWorkflow(
                $workflowService->workflowPath($this->workspacePath, $childWorkflowName),
            );

            $child = new self(
                timestamp: $workflowService->availableLogTimestamp($workspaceData, $childWorkflowName, now()->timestamp),
                projectUuid: $this->projectUuid,
                workspacePath: $this->workspacePath,
                workflowName: $childWorkflowName,
                stepHashes: $childWorkflowData->stepHashes(),
                parent: $this->workflowRunLogData->id,
                timeoutSeconds: $this->timeoutSeconds,
                ancestorWorkflowNames: $chain,
                statusBeforeRun: $this->statusBeforeRun,
            );

            $child->handle();
        } catch (Throwable $throwable) {
            // the child writes its log before it runs a step, so a throw part way through still leaves one to link to
            $logStep->log_id = ($child ?? null)?->workflowRunLogData?->id;

            return $this->failChildWorkflowStep($logStep, $throwable->getMessage(), $stepContext);
        }

        $childRunLogData = $child->workflowRunLogData;
        $successful = $childRunLogData?->status === WorkflowStatus::SUCCESS;

        $logStep->log_id = $childRunLogData?->id;
        $logStep->output = 'Workflow ['.$childWorkflowName.'] finished with status ['.($childRunLogData?->status->value ?? WorkflowStatus::FAILED->value).'].';
        $logStep->exitCode = $successful ? 0 : 1;
        $logStep->ended_timestamp = now()->timestamp;
        $this->flushRunLog();

        Log::info('workflow: child workflow completed', [
            ...$stepContext,
            'child_workflow_name' => $childWorkflowName,
            'child_log_id' => $childRunLogData?->id,
            'child_status' => $childRunLogData?->status->value,
        ]);

        return $successful;
    }

    /**
     * Record a workflow step that never got as far as running its child workflow.
     *
     * @param  array<string, mixed>  $stepContext
     */
    protected function failChildWorkflowStep(WorkflowRunLogStepData $logStep, string $reason, array $stepContext): bool
    {
        Log::info('workflow: child workflow not run', [
            ...$stepContext,
            'reason' => $reason,
        ]);

        $logStep->output = $reason;
        $logStep->exitCode = 1;
        $logStep->ended_timestamp = now()->timestamp;
        $this->flushRunLog();

        return false;
    }

    /**
     * Persist the current state of the run log, which steps mutate in place as they progress.
     */
    protected function flushRunLog(): void
    {
        app(WorkflowService::class)->writeWorkflowLogData($this->logFilePath, $this->workflowRunLogData);
    }

    /**
     * Mark the steps a failed run never got to, so they read as abandoned rather than upcoming.
     */
    protected function markUnreachedStepsAborted(): void
    {
        $this->workflowRunLogData->steps
            ->filter(fn (WorkflowRunLogStepData $step) => $step->isPending())
            ->each(function (WorkflowRunLogStepData $step): void {
                $step->skip_reason = WorkflowStepSkipReason::ABORTED;
            });
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
     * Build a pending process for a step, carrying the job's per-process budget where zero means none.
     *
     * @param  array<string, string|false>  $env  already sanitized by ProcessEnvironmentService
     */
    protected function pendingProcess(string $cwd, array $env): PendingProcess
    {
        return Process::path($cwd)
            ->env($env)
            ->when(
                $this->timeoutSeconds > 0,
                fn (PendingProcess $pending): PendingProcess => $pending->timeout($this->timeoutSeconds),
                fn (PendingProcess $pending): PendingProcess => $pending->forever(),
            );
    }

    /**
     * Run a step's if or unless command and return the finished result for exit code inspection.
     *
     * The command is deliberately not wrapped by strictShellCommand(): a gate's raw exit code is
     * the signal, so a non-zero status is an answer rather than a failure.
     *
     * @param  array<string, string|false>  $env  already sanitized by ProcessEnvironmentService
     */
    protected function evaluateGate(string $command, string $cwd, array $env): ProcessResult
    {
        return $this->pendingProcess($cwd, $env)->run($command);
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
            'ancestors' => $this->ancestorWorkflowNames,
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
                logId: $this->workflowRunLogData?->id,
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
