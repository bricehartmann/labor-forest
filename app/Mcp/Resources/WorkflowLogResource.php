<?php

namespace App\Mcp\Resources;

use App\Concerns\Mcp\RespondsWithJson;
use App\Data\ProjectData;
use App\Data\WorkflowRunLogData;
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

#[Name('workflow-log')]
#[Title('WorkflowLog')]
#[Description('Get an individual run log, with the output of every step, by run log id for a workflow by name for a workspace by kebab-slug for a project by UUID.')]
#[MimeType('application/json')]
class WorkflowLogResource extends Resource implements HasUriTemplate
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

        /** @var WorkflowRunLogData|null $log */
        $log = rescue(fn () => app(WorkflowService::class)->loadWorkflowLogDatum($workspace, (string) $request->get('id')));

        // the name is part of the uri, so a log of another workflow is not addressable through it
        if (! $log || $log->name !== $request->get('name')) {
            return Response::error('Failed to load workflow log.');
        }

        return $this->json($log->toMcpResource());
    }

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('laborforest://projects/{uuid}/workspaces/{slugKebab}/workflows/{name}/logs/{id}');
    }
}
