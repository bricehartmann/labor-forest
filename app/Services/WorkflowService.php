<?php

namespace App\Services;

use App\Data\WorkflowData;
use App\Data\WorkflowRunLogData;
use App\Data\WorkflowRunLogStepData;
use App\Data\WorkflowStepData;
use App\Data\WorkspaceData;
use App\Enums\Directory;
use App\Enums\FileExtension;
use App\Enums\WorkflowStatus;
use App\Enums\WorkspaceStatus;
use App\Enums\YamlResourceType;
use App\Exceptions\InvalidWorkflowFile;
use App\Exceptions\WorkspaceNotFound;
use App\Jobs\RunWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class WorkflowService
{
    public function ensureLogFilePathDirectoryExists(string $workspacePath): string
    {
        $path = $this->logsPath($workspacePath);

        if (! File::exists($path)) {
            File::makeDirectory($path);
        }

        return $path;
    }

    public function writeWorkflowLogData(string $logFilePath, WorkflowRunLogData $workflowRunLogData): void
    {
        File::put($logFilePath, Yaml::dump($workflowRunLogData->toArray(), 10));
    }

    /**
     * Build a run log seeded with a pending entry for every step of the workflow, so the log
     * lists the steps still to come rather than only the ones that have already run.
     *
     * @param  ?string  $parentLogId  the run log id of the workflow that started this one, when chained
     * @param  Collection<int, WorkflowStepData>  $workflowSteps
     */
    public function workflowRunLogData(
        int $timestamp,
        WorkspaceData $workspaceData,
        string $workflowName,
        ?string $parentLogId,
        WorkflowStatus $status,
        Collection $workflowSteps,
    ): WorkflowRunLogData {
        return new WorkflowRunLogData(
            id: $this->runLogId($workspaceData, $timestamp, $workflowName),
            name: $workflowName,
            parent: $parentLogId,
            timestamp: $timestamp,
            status: $status,
            exception: null,
            steps: $workflowSteps
                ->values()
                ->map(fn (WorkflowStepData $step, int $index) => WorkflowRunLogStepData::pending($step, $step->hash((string) $index))),
        );
    }

    /**
     * Build the id identifying a run, which doubles as the name of its log file.
     */
    public function runLogId(WorkspaceData $workspaceData, int $timestamp, string $workflowName): string
    {
        return Carbon::createFromTimestampUTC($timestamp)->format('Ymd\THis\Z')
            .'_'.$workspaceData->slugKebab()
            .'_'.Str::slug($workflowName);
    }

    /**
     * Move the given timestamp forward until no run log exists for the id it would produce.
     *
     * A run is identified by its timestamp, workspace and workflow name, so a workflow starting
     * the same child twice within one second would otherwise overwrite the first child's log.
     *
     * Only call this when creating a run: RunWorkflow rebuilds its log data from the timestamp
     * it was constructed with and has to derive the same id it was dispatched under.
     */
    public function availableLogTimestamp(WorkspaceData $workspaceData, string $workflowName, int $timestamp): int
    {
        $logsPath = $this->logsPath($workspaceData->path);

        while (File::isFile($logsPath.DIRECTORY_SEPARATOR.$this->runLogId($workspaceData, $timestamp, $workflowName).'.'.FileExtension::YAML->value)) {
            $timestamp++;
        }

        return $timestamp;
    }

    /**
     * @throws InvalidWorkflowFile
     * @throws WorkspaceNotFound
     */
    public function dispatchWorkflow(string $projectUuid, string $workspacePath, string $workflowName, array $stepHashes, ?string $parentLogId, int $timeoutSeconds): string
    {
        $projectService = app(ProjectsService::class);
        $workspaceData = $projectService->loadProjectWorkspace($workspacePath);
        $projectService->updateProjectWorkspaceStatus($workspaceData->path, WorkspaceStatus::PENDING);
        $timestamp = $this->availableLogTimestamp($workspaceData, $workflowName, now()->timestamp);
        $workflowRunLogData = $this->workflowRunLogData(
            timestamp: $timestamp,
            workspaceData: $workspaceData,
            workflowName: $workflowName,
            parentLogId: $parentLogId,
            status: WorkflowStatus::PENDING,
            workflowSteps: $this->loadSteps($workspacePath, $workflowName),
        );
        $logFileName = $workflowRunLogData->id.'.'.FileExtension::YAML->value;
        $logFilePath = $this->ensureLogFilePathDirectoryExists($workspaceData->path).DIRECTORY_SEPARATOR.$logFileName;
        $this->writeWorkflowLogData($logFilePath, $workflowRunLogData);

        dispatch(new RunWorkflow($timestamp, $projectUuid, $workspacePath, $workflowName, $stepHashes, $parentLogId, $timeoutSeconds));

        return $workflowRunLogData->id;
    }

    /**
     * @throws InvalidWorkflowFile
     */
    public function loadSteps(string $workspacePath, string $workflowName): Collection
    {
        $workflowPath = implode(DIRECTORY_SEPARATOR, [
            $workspacePath,
            Directory::BASE->value,
            Directory::WORKFLOWS->value,
            $workflowName.'.'.FileExtension::YAML->value,
        ]);

        $workflow = $this->loadWorkflow($workflowPath);

        return $workflow->steps;
    }

    /**
     * @return Collection<string, WorkflowData> keyed by the workflow file name without its extension
     */
    public function loadWorkflows(string $workspacePath): Collection
    {
        $workflowsPath = implode(DIRECTORY_SEPARATOR, [
            $workspacePath,
            Directory::BASE->value,
            Directory::WORKFLOWS->value,
        ]);

        if (! File::isDirectory($workflowsPath)) {
            return collect();
        }

        return collect(File::files($workflowsPath))
            ->reject(fn (SplFileInfo $file) => $file->getExtension() !== FileExtension::YAML->value)
            ->filter(function (SplFileInfo $file) {
                $yaml = rescue(fn () => Yaml::parseFile($file->getPathname()));

                return is_array($yaml) && ($yaml['resource_type'] ?? null) === YamlResourceType::WORKFLOW->value;
            })
            ->mapWithKeys(fn (SplFileInfo $file) => [
                $file->getFilenameWithoutExtension() => $this->loadWorkflow($file->getPathname()),
            ])
            ->filter()
            ->reject(fn (WorkflowData $data) => $data->steps->isEmpty())
            ->sortBy(fn (WorkflowData $data) => $data->sort_order);
    }

    /**
     * Read the run logs written by RunWorkflow for a single workspace.
     *
     * Unparseable or malformed logs are skipped rather than throwing: logs are machine-written
     * runtime artifacts flushed incrementally while a workflow is still running.
     *
     * @return Collection<int, WorkflowRunLogData> newest run first
     */
    public function loadWorkflowLogData(WorkspaceData $workspaceData): Collection
    {
        $logsPath = $this->logsPath($workspaceData->path);

        if (! File::isDirectory($logsPath)) {
            return collect();
        }

        $fileNamePattern = $this->logFileNamePattern($workspaceData);

        return collect(File::files($logsPath))
            ->reject(fn (SplFileInfo $file) => $file->getExtension() !== FileExtension::YAML->value)
            ->filter(fn (SplFileInfo $file) => preg_match($fileNamePattern, $file->getFilenameWithoutExtension()) === 1)
            ->map(fn (SplFileInfo $file) => rescue(fn () => Yaml::parseFile($file->getPathname())))
            ->filter(fn ($yaml) => is_array($yaml) && ($yaml['resource_type'] ?? null) === YamlResourceType::WORKFLOW_RUN_LOG->value)
            ->map(fn (array $yaml) => rescue(fn () => WorkflowRunLogData::from($yaml)))
            ->filter()
            ->sortByDesc(fn (WorkflowRunLogData $data) => $data->timestamp)
            ->values();
    }

    /**
     * Read a single run log by its id, which is the log file name without its extension.
     *
     * Returns null when the file is missing, unparseable, or malformed: logs are machine-written
     * runtime artifacts flushed incrementally while a workflow is still running.
     */
    public function loadWorkflowLogDatum(WorkspaceData $workspaceData, string $id): ?WorkflowRunLogData
    {
        if (preg_match($this->logFileNamePattern($workspaceData), $id) !== 1) {
            return null;
        }

        $path = $this->logsPath($workspaceData->path).DIRECTORY_SEPARATOR.$id.'.'.FileExtension::YAML->value;

        if (! File::isFile($path)) {
            return null;
        }

        $yaml = rescue(fn () => Yaml::parseFile($path));

        if (! is_array($yaml) || ($yaml['resource_type'] ?? null) !== YamlResourceType::WORKFLOW_RUN_LOG->value) {
            return null;
        }

        return rescue(fn () => WorkflowRunLogData::from($yaml));
    }

    private function logsPath(string $workspacePath): string
    {
        return implode(DIRECTORY_SEPARATOR, [
            $workspacePath,
            Directory::BASE->value,
            Directory::IGNORED->value,
            Directory::LOGS->value,
        ]);
    }

    private function logFileNamePattern(WorkspaceData $workspaceData): string
    {
        return '/^\d{8}T\d{6}Z_'.preg_quote($workspaceData->slugKebab(), '/').'_.+$/';
    }

    /**
     * @throws InvalidWorkflowFile when the file is missing, unparseable, malformed, or fails validation
     */
    public function loadWorkflow(string $path): WorkflowData
    {
        try {
            $yaml = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw InvalidWorkflowFile::fromParseError($path, $e);
        }

        if ($yaml !== null && ! is_array($yaml)) {
            throw InvalidWorkflowFile::notAMapping($path, get_debug_type($yaml));
        }

        try {
            return WorkflowData::validateAndCreate($yaml ?? []);
        } catch (ValidationException $e) {
            throw InvalidWorkflowFile::fromValidation($path, $e);
        }
    }
}
