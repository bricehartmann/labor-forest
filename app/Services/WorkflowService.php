<?php

namespace App\Services;

use App\Data\WorkflowData;
use App\Data\WorkflowRunLogData;
use App\Data\WorkspaceData;
use App\Enums\Directory;
use App\Enums\FileExtension;
use App\Enums\WorkflowStatus;
use App\Enums\YamlResourceType;
use App\Exceptions\InvalidWorkflowFile;
use App\Jobs\RunWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class WorkflowService
{
    public function ensureLogFilePathDirectoryExists(string $workspacePath): string
    {
        $path = implode(DIRECTORY_SEPARATOR, [
            $workspacePath,
            Directory::BASE->value,
            Directory::IGNORED->value,
            Directory::LOGS->value,
        ]);

        if (! File::exists($path)) {
            File::makeDirectory($path);
        }

        return $path;
    }

    public function writeWorkflowLogData(string $logFilePath, WorkflowRunLogData $workflowRunLogData): void
    {
        File::put($logFilePath, Yaml::dump($workflowRunLogData->toArray(), 10));
    }

    public function workflowRunLogData(
        int $timestamp,
        WorkspaceData $workspaceData,
        string $workflowName,
        ?string $parentWorkflowName,
        WorkflowStatus $status,
    ): WorkflowRunLogData
    {
        $now = Carbon::createFromTimestampUTC($timestamp);
        $logFileId = $now->format('Ymd\THis\Z').'_'.$workspaceData->slugKebab().'_'.Str::slug($workflowName);

       return new WorkflowRunLogData(
            id: $logFileId,
            name: $workflowName,
            parent: $parentWorkflowName,
            timestamp: $timestamp,
            status: $status,
            exception: null,
            steps: collect(),
        );
    }

    public function dispatchWorkflow(string $projectUuid, string $workspacePath, string $workflowName, array $stepHashes, ?string $parent, int $timeoutSeconds): void
    {
        $projectService = app(ProjectsService::class);
        $workspaceData = $projectService->loadProjectWorkspace($workspacePath);
        $timestamp = now()->timestamp;
        $workflowRunLogData = $this->workflowRunLogData(
            timestamp: $timestamp,
            workspaceData: $workspaceData,
            workflowName: $workflowName,
            parentWorkflowName: $parent,
            status: WorkflowStatus::PENDING,
        );
        $logFileName = $workflowRunLogData->id.'.'.FileExtension::YAML->value;
        $logFilePath = $this->ensureLogFilePathDirectoryExists($workspaceData->path).DIRECTORY_SEPARATOR.$logFileName;
        $this->writeWorkflowLogData($logFilePath, $workflowRunLogData);

        dispatch(new RunWorkflow($timestamp, $projectUuid, $workspacePath, $workflowName, $stepHashes, $parent, $timeoutSeconds));
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
        $logsPath = implode(DIRECTORY_SEPARATOR, [
            $workspaceData->path,
            Directory::BASE->value,
            Directory::IGNORED->value,
            Directory::LOGS->value,
        ]);

        if (! File::isDirectory($logsPath)) {
            return collect();
        }

        $fileNamePattern = '/^\d{8}T\d{6}Z_'.preg_quote($workspaceData->slugKebab(), '/').'_.+$/';

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
