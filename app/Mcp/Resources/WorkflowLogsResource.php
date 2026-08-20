<?php

namespace App\Mcp\Resources;

use App\Concerns\Mcp\RespondsWithJson;
use App\Data\ProjectData;
use App\Data\WorkflowRunLogSummaryData;
use App\Data\WorkspaceData;
use App\Services\ProjectsService;
use App\Services\WorkflowService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Name('workflow-logs')]
#[Title('WorkflowLogs')]
#[Description('List the run logs, without their steps, of a workflow by name for a workspace by kebab-slug for a project by UUID.')]
#[MimeType('application/json')]
class WorkflowLogsResource extends Resource implements HasUriTemplate
{
    use RespondsWithJson;

    /**
     * Handle the resource request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $projectService = app(ProjectsService::class);

        /** @var ProjectData|null $project */
        $project = rescue(fn () => $projectService->loadProjects()->firstWhere('uuid', $request->get('uuid')));

        if (! $project) {
            return Response::error('Failed to load project.');
        }

        /** @var WorkspaceData|null $workspace */
        $workspace = rescue(
            fn () => $projectService
                ->loadProjectWorkspaces($project->path)
                ->firstWhere(fn (WorkspaceData $data) => $data->slugKebab() === $request->get('slugKebab'))
        );

        if (! $workspace) {
            return Response::error('Failed to load workspace.');
        }

        $logs = rescue(fn () => app(WorkflowService::class)->loadWorkflowLogSummaryData($workspace));

        if ($logs === null) {
            return Response::error('Failed to load workflow logs.');
        }

        return $this->json(
            $logs
                ->filter(fn (WorkflowRunLogSummaryData $data) => $data->name === $request->get('name'))
                ->map(fn (WorkflowRunLogSummaryData $data) => $data->toMcpResource())
                ->values()
                ->all()
        );
    }

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('laborforest://projects/{uuid}/workspaces/{slugKebab}/workflows/{name}/logs');
    }
}
