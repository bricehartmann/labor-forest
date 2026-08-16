<?php

namespace App\Services;

use App\Concerns\Services\ManagesFiles;
use App\Data\PendingCliCommandData;
use App\Data\WorkflowStepData;
use App\Enums\CliCommand;
use App\Enums\Directory;
use App\Enums\Disk;
use App\Enums\File;
use App\Enums\FileExtension;
use App\Exceptions\InstallCliToolsFailed;
use App\Exceptions\InvalidWorkflowFile;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Project;
use App\Filament\Pages\WorkflowLog;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class CliToolsService
{
    use ManagesFiles;

    public function installCliTools(string $path): void
    {
        $cliToolsPath = Storage::disk(Disk::EXTRAS->value)->path(implode(DIRECTORY_SEPARATOR, [
            Directory::BIN->value,
            File::CLI_TOOLS->value,
        ]));

        $outputPath = $path.DIRECTORY_SEPARATOR.File::CLI_TOOLS->value;

        $shellCmd = sprintf(
            'ln -sf %s %s && chmod +x %s',
            escapeshellarg($cliToolsPath),
            escapeshellarg($outputPath),
            escapeshellarg($outputPath)
        );

        $process = Process::run($shellCmd);

        if ($process->successful()) {
            return;
        }

        $appleScript = sprintf(
            'do shell script "%s" with administrator privileges with prompt "LaborForest wants to install CLI tools."',
            str_replace('"', '\"', $shellCmd)
        );

        $result = Process::run(['osascript', '-e', $appleScript]);

        if (! $result->successful()) {
            throw new InstallCliToolsFailed($path);
        }
    }

    /**
     * Run whatever the `lf` script asked for, and report the page to land on.
     *
     * Returns null when there was nothing pending. Failures come back as a dashboard URL carrying
     * the message rather than as an exception, because both callers can only respond by showing
     * the user a page.
     */
    public function runPendingCommand(): ?string
    {
        $pending = $this->pullPendingCommand();

        if ($pending === null) {
            return null;
        }

        try {
            return match ($pending->command) {
                CliCommand::ADD_PROJECT => $this->addProject($pending),
                CliCommand::RUN_WORKFLOW => $this->runWorkflow($pending),
            };
        } catch (Throwable $th) {
            return $this->dashboardUrl($th->getMessage());
        }
    }

    /**
     * @throws Throwable
     */
    private function addProject(PendingCliCommandData $pending): string
    {
        if (! \Illuminate\Support\Facades\File::isDirectory($pending->path)) {
            return $this->dashboardUrl('Path does not exist.');
        }

        $projectData = app(ProjectsService::class)->addProject($pending->path);

        return Project::getUrl(['uuid' => $projectData->uuid]);
    }

    /**
     * @throws Throwable
     */
    private function runWorkflow(PendingCliCommandData $pending): string
    {
        if (! \Illuminate\Support\Facades\File::isDirectory($pending->path)) {
            return $this->dashboardUrl('Path does not exist.');
        }

        $workflow = $pending->workflow;

        if (! $workflow || ! \Illuminate\Support\Facades\File::isFile(implode(DIRECTORY_SEPARATOR, [
            $pending->path,
            Directory::BASE->value,
            Directory::WORKFLOWS->value,
            $workflow.'.'.FileExtension::YAML->value,
        ]))) {
            return $this->dashboardUrl('Workflow does not exist.');
        }

        $workspaceData = app(ProjectsService::class)->loadProjectWorkspace($pending->path);
        $projectData = app(ProjectsService::class)->loadProjectFromWorkspace($workspaceData->path);

        if (! $projectData) {
            return $this->dashboardUrl('Project does not exist.');
        }

        $settings = app(SettingsService::class)->loadSettings();

        $workflowRunLogId = app(WorkflowService::class)->dispatchWorkflow(
            projectUuid: $projectData->uuid,
            workspacePath: $workspaceData->path,
            workflowName: $workflow,
            stepHashes: $this->allStepHashes($workspaceData->path, $workflow),
            parentLogId: null,
            timeoutSeconds: $settings->workflow_timeout_seconds,
        );

        return WorkflowLog::getUrl([
            'uuid' => $projectData->uuid,
            'slug' => $workspaceData->slugKebab(),
            'id' => $workflowRunLogId,
        ]);
    }

    /**
     * The dashboard reads the message off the query string rather than the session, because the
     * callers run outside the window's own request and share no session with it.
     */
    private function dashboardUrl(string $error): string
    {
        return Dashboard::getUrl(['error' => $error]);
    }

    /**
     * The hashes of every step of a workflow, so a run started from the CLI runs the whole thing.
     *
     * @return array<int, string>
     *
     * @throws InvalidWorkflowFile
     */
    private function allStepHashes(string $workspacePath, string $workflowName): array
    {
        return app(WorkflowService::class)
            ->loadSteps($workspacePath, $workflowName)
            ->map(fn (WorkflowStepData $step, int $index) => $step->hash((string) $index))
            ->all();
    }

    /**
     * Read and remove the request the `lf` script left behind, if there is one.
     *
     * The file is deleted before it is parsed, so a malformed one cannot wedge every future
     * launch, and so a deeplink arriving after the boot drain finds nothing left to run.
     */
    public function pullPendingCommand(): ?PendingCliCommandData
    {
        if (! $this->baseFileExists(File::PENDING_CLI_COMMAND->value)) {
            return null;
        }

        $contents = $this->getBaseFile(File::PENDING_CLI_COMMAND->value);

        $this->deleteBaseFile(File::PENDING_CLI_COMMAND->value);

        try {
            $yaml = Yaml::parse($contents);
        } catch (ParseException) {
            return null;
        }

        if (! is_array($yaml)) {
            return null;
        }

        try {
            return PendingCliCommandData::validateAndCreate($yaml);
        } catch (ValidationException) {
            return null;
        }
    }

    public function dismissCliToolsWidget(): void
    {
        $settingsService = app(SettingsService::class);
        $settingsData = $settingsService->loadSettings();
        $settingsData->cli_tools_dismissed = true;
        $settingsService->saveSettings($settingsData);
    }
}
