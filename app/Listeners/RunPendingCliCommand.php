<?php

namespace App\Listeners;

use App\Data\PendingCliCommandData;
use App\Data\WorkflowStepData;
use App\Enums\CliCommand;
use App\Enums\Directory;
use App\Enums\FileExtension;
use App\Exceptions\InvalidWorkflowFile;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Project;
use App\Filament\Pages\WorkflowLog;
use App\Services\CliToolsService;
use App\Services\ProjectsService;
use App\Services\SettingsService;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\File;
use Native\Desktop\Facades\Window;
use Throwable;

/**
 * Run whatever the `lf` script asked for and drive the window to the resulting page.
 *
 * A deeplink is not an HTTP request — Electron turns it into an event, so the request itself
 * travels through ~/.laborforest/pending.yaml instead. This runs on both the deeplink event and
 * on boot, because a cold-start deeplink fires before the PHP server is listening and is dropped.
 */
class RunPendingCliCommand
{
    /**
     * Registered for both OpenedFromURL and ApplicationBooted, so the event itself is ignored;
     * the pending file is the payload.
     */
    public function handle(object $event): void
    {
        $pending = app(CliToolsService::class)->pullPendingCommand();

        if ($pending === null) {
            return;
        }

        try {
            $this->navigateTo(match ($pending->command) {
                CliCommand::ADD_PROJECT => $this->addProject($pending),
                CliCommand::RUN_WORKFLOW => $this->runWorkflow($pending),
            });
        } catch (Throwable $th) {
            $this->navigateTo($this->dashboardUrl($th->getMessage()));
        }
    }

    /**
     * Point the open window at a page.
     *
     * Overridden in tests, which have no Electron runtime to navigate.
     */
    protected function navigateTo(string $url): void
    {
        Window::current()->url($url);
    }

    /**
     * @throws Throwable
     */
    protected function addProject(PendingCliCommandData $pending): string
    {
        if (! File::isDirectory($pending->path)) {
            return $this->dashboardUrl('Path does not exist.');
        }

        $projectData = app(ProjectsService::class)->addProject($pending->path);

        return Project::getUrl(['uuid' => $projectData->uuid]);
    }

    /**
     * @throws Throwable
     */
    protected function runWorkflow(PendingCliCommandData $pending): string
    {
        if (! File::isDirectory($pending->path)) {
            return $this->dashboardUrl('Path does not exist.');
        }

        $workflow = $pending->workflow;

        if (! $workflow || ! File::isFile(implode(DIRECTORY_SEPARATOR, [
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
     * The dashboard reads the message off the query string rather than the session, because this
     * runs in the native event request, which shares no session with the window.
     */
    protected function dashboardUrl(string $error): string
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
    protected function allStepHashes(string $workspacePath, string $workflowName): array
    {
        return app(WorkflowService::class)
            ->loadSteps($workspacePath, $workflowName)
            ->map(fn (WorkflowStepData $step, int $index) => $step->hash((string) $index))
            ->all();
    }
}
