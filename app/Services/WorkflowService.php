<?php

namespace App\Services;

use App\Concerns\Services\ResolvesWorkflowFiles;
use App\Data\WorkflowData;
use App\Data\WorkflowRunLogData;
use App\Data\WorkflowRunLogStepData;
use App\Data\WorkflowRunLogSummaryData;
use App\Data\WorkflowStepData;
use App\Data\WorkspaceData;
use App\Enums\Directory;
use App\Enums\FileExtension;
use App\Enums\WorkflowStatus;
use App\Enums\WorkspaceStatus;
use App\Enums\YamlResourceType;
use App\Exceptions\InvalidWorkflowFile;
use App\Exceptions\WorkflowLogsNotDeleted;
use App\Exceptions\WorkflowNotRunnable;
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
    use ResolvesWorkflowFiles;

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
     * @param  ?array<int, string>  $stepHashes  the steps to run, or null to run every step of the workflow
     *
     * @throws InvalidWorkflowFile
     * @throws WorkflowNotRunnable
     * @throws WorkspaceNotFound
     */
    public function dispatchWorkflow(string $projectUuid, string $workspacePath, string $workflowName, ?array $stepHashes, ?string $parentLogId, int $timeoutSeconds): string
    {
        $projectService = app(ProjectsService::class);
        $workspaceData = $projectService->loadProjectWorkspace($workspacePath);
        $workflowData = $this->loadWorkflow($this->workflowPath($workspacePath, $workflowName));

        $this->ensureWorkspaceCanRunWorkflow($workspaceData, $workflowName, $workflowData);

        $projectService->updateProjectWorkspaceStatus($workspaceData->path, WorkspaceStatus::PENDING);
        $timestamp = $this->availableLogTimestamp($workspaceData, $workflowName, now()->timestamp);
        $workflowRunLogData = $this->workflowRunLogData(
            timestamp: $timestamp,
            workspaceData: $workspaceData,
            workflowName: $workflowName,
            parentLogId: $parentLogId,
            status: WorkflowStatus::PENDING,
            workflowSteps: $workflowData->steps,
        );
        $logFileName = $workflowRunLogData->id.'.'.FileExtension::YAML->value;
        $logFilePath = $this->ensureLogFilePathDirectoryExists($workspaceData->path).DIRECTORY_SEPARATOR.$logFileName;
        $this->writeWorkflowLogData($logFilePath, $workflowRunLogData);

        dispatch(new RunWorkflow(
            timestamp: $timestamp,
            projectUuid: $projectUuid,
            workspacePath: $workspacePath,
            workflowName: $workflowName,
            stepHashes: $stepHashes ?? $workflowData->stepHashes(),
            parent: $parentLogId,
            timeoutSeconds: $timeoutSeconds,
            // read before the pending write above, so a workflow with no `ending_status` can put the
            // workspace back where it started instead of stranding it on `pending`
            statusBeforeRun: $workspaceData->status,
        ));

        return $workflowRunLogData->id;
    }

    /**
     * Refuse to start a workflow the workspace is not in a position to run.
     *
     * A workflow can name the status the workspace has to be in for it to make sense — an `up`
     * that boots a suspended workspace should not run against a running one — and no workflow may
     * be started while another is already working.
     *
     * @throws WorkflowNotRunnable
     */
    public function ensureWorkspaceCanRunWorkflow(WorkspaceData $workspaceData, string $workflowName, WorkflowData $workflowData): void
    {
        if ($workspaceData->status->allowsWorkflowRequiring($workflowData->require_status)) {
            return;
        }

        throw new WorkflowNotRunnable($workflowName, $workspaceData->status, $workflowData->require_status);
    }

    /**
     * @throws InvalidWorkflowFile
     */
    public function loadSteps(string $workspacePath, string $workflowName): Collection
    {
        return $this->loadWorkflow($this->workflowPath($workspacePath, $workflowName))->steps;
    }

    /**
     * @return Collection<string, WorkflowData> keyed by the workflow file name without its extension
     */
    public function loadWorkflows(string $workspacePath): Collection
    {
        $workflowsPath = $this->workflowsPath($workspacePath);

        if (! File::isDirectory($workflowsPath)) {
            return collect();
        }

        return collect(File::files($workflowsPath))
            ->reject(fn (SplFileInfo $file) => ! FileExtension::isYaml($file->getExtension()))
            /**
             * A workflow is keyed by its file name without the extension, so `up.yaml` and `up.yml`
             * would collide on one key in whatever order the directory happens to be read. Dropping
             * the `.yml` here settles it before that can happen rather than relying on that order.
             */
            ->reject(fn (SplFileInfo $file) => $file->getExtension() === FileExtension::YML->value
                && File::isFile($workflowsPath.DIRECTORY_SEPARATOR.$file->getFilenameWithoutExtension().'.'.FileExtension::YAML->value))
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
     * Read the run logs written by RunWorkflow for a single workspace, without their steps.
     *
     * Unparseable or malformed logs are skipped rather than throwing: logs are machine-written
     * runtime artifacts flushed incrementally while a workflow is still running.
     *
     * @return Collection<int, WorkflowRunLogSummaryData> newest run first
     */
    public function loadWorkflowLogSummaryData(WorkspaceData $workspaceData): Collection
    {
        $logsPath = $this->logsPath($workspaceData->path);

        if (! File::isDirectory($logsPath)) {
            return collect();
        }

        $fileNamePattern = $this->logFileNamePattern($workspaceData);

        return collect(File::files($logsPath))
            ->reject(fn (SplFileInfo $file) => $file->getExtension() !== FileExtension::YAML->value)
            ->filter(fn (SplFileInfo $file) => preg_match($fileNamePattern, $file->getFilenameWithoutExtension()) === 1)
            ->map(fn (SplFileInfo $file) => $this->loadWorkflowLogSummaryDatum($file->getPathname()))
            ->filter()
            ->sortByDesc(fn (WorkflowRunLogSummaryData $data) => $data->timestamp)
            ->values();
    }

    /**
     * Read one run log as a summary, reading past its step output rather than through it.
     *
     * A run's streamed output can reach megabytes, so the file is first read as a header with the
     * steps block dropped. Falling back to a full parse covers a log the header strip cannot make
     * sense of, which a machine-written one never is.
     */
    private function loadWorkflowLogSummaryDatum(string $path): ?WorkflowRunLogSummaryData
    {
        $summary = $this->makeLogSummary(rescue(fn () => Yaml::parse($this->logHeaderWithoutSteps($path))));

        return $summary ?? $this->makeLogSummary(rescue(fn () => Yaml::parseFile($path)));
    }

    /**
     * Read a log file line by line, keeping everything outside its steps block.
     *
     * The block is found wherever it sits rather than assumed to be last, because a log written
     * by hand is under no obligation to order its keys the way Yaml::dump() does.
     */
    private function logHeaderWithoutSteps(string $path): string
    {
        $inSteps = false;

        return File::lines($path)
            ->reject(function (string $line) use (&$inSteps) {
                if ($inSteps) {
                    if (trim($line) === '' || in_array($line[0], [' ', "\t", '-'], strict: true)) {
                        return true;
                    }

                    $inSteps = false;
                }

                if (preg_match('/^steps\s*:/', $line) === 1) {
                    $inSteps = true;

                    return true;
                }

                return false;
            })
            ->implode(PHP_EOL);
    }

    /**
     * Hydrate a run log summary from parsed YAML, or null when the YAML is not a run log.
     */
    private function makeLogSummary(mixed $yaml): ?WorkflowRunLogSummaryData
    {
        if (! is_array($yaml) || ($yaml['resource_type'] ?? null) !== YamlResourceType::WORKFLOW_RUN_LOG->value) {
            return null;
        }

        return rescue(fn () => WorkflowRunLogSummaryData::from($yaml));
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

    /**
     * Delete the run logs one workflow has written in one workspace.
     *
     * A run still pending or running is counted and left on disk rather than deleted, because the
     * RunWorkflow job holding it is still flushing step output into that file.
     *
     * @return array{purged: int, skipped: int}
     *
     * @throws WorkflowLogsNotDeleted
     */
    public function purgeWorkflowLogs(WorkspaceData $workspaceData, string $workflowName): array
    {
        [$locked, $purgeable] = $this->loadWorkflowLogSummaryData($workspaceData)
            ->filter(fn (WorkflowRunLogSummaryData $data) => $data->name === $workflowName)
            ->partition(fn (WorkflowRunLogSummaryData $data) => $data->status->isLocked());

        if ($purgeable->isEmpty()) {
            return ['purged' => 0, 'skipped' => $locked->count()];
        }

        $logsPath = $this->logsPath($workspaceData->path);
        $paths = $purgeable
            ->map(fn (WorkflowRunLogSummaryData $data) => $logsPath.DIRECTORY_SEPARATOR.$data->id.'.'.FileExtension::YAML->value)
            ->values()
            ->all();

        if (! File::delete($paths)) {
            throw new WorkflowLogsNotDeleted($workflowName);
        }

        return ['purged' => $purgeable->count(), 'skipped' => $locked->count()];
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
