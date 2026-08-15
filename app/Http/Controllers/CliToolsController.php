<?php

namespace App\Http\Controllers;

use App\Data\WorkflowStepData;
use App\Enums\Directory;
use App\Enums\FileExtension;
use App\Exceptions\InvalidWorkflowFile;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Project;
use App\Filament\Pages\WorkflowLog;
use App\Services\ProjectsService;
use App\Services\SettingsService;
use App\Services\WorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Throwable;

class CliToolsController extends Controller
{
    public function addProject(Request $request): RedirectResponse
    {
        dd('here');

        $path = $request->query('path');

        if (! $path || ! File::isDirectory($path)) {
            return redirect(Dashboard::getUrl())->with('error', 'Path does not exist.');
        }

        try {
            $projectData = app(ProjectsService::class)->addProject($path);
        } catch (Throwable $th) {
            return redirect(Dashboard::getUrl())->with('error', $th->getMessage());
        }

        return redirect(Project::getUrl(['uuid' => $projectData->uuid]));
    }

    public function runWorkflow(Request $request): RedirectResponse
    {
        $path = $request->query('path');

        if (! $path || ! File::isDirectory($path)) {
            return redirect(Dashboard::getUrl())->with('error', 'Path does not exist.');
        }

        $workflow = $request->query('workflow');

        if (! $workflow || ! File::isFile(implode(DIRECTORY_SEPARATOR, [
            $path,
            Directory::BASE->value,
            Directory::WORKFLOWS->value,
            $workflow.'.'.FileExtension::YAML->value,
        ]))) {
            return redirect(Dashboard::getUrl())->with('error', 'Workflow does not exist.');
        }

        try {
            $workspaceData = app(ProjectsService::class)->loadProjectWorkspace($path);
            $projectData = app(ProjectsService::class)->loadProjectFromWorkspace($workspaceData->path);

            if (! $projectData) {
                return redirect(Dashboard::getUrl())->with('error', 'Project does not exist.');
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

            return redirect(WorkflowLog::getUrl([
                'uuid' => $projectData->uuid,
                'slug' => $workspaceData->slugKebab(),
                'id' => $workflowRunLogId,
            ]));
        } catch (Throwable $th) {
            return redirect(Dashboard::getUrl())->with('error', $th->getMessage());
        }
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
