<?php

namespace App\Mcp\Resources;

use App\Concerns\Mcp\RespondsWithJson;
use App\Data\ProjectData;
use App\Data\WorkspaceData;
use App\Services\ProjectsService;
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

#[Name('workspaces')]
#[Title('Workspaces')]
#[Description('List the workspaces for a project by UUID.')]
#[MimeType('application/json')]
class WorkspacesResource extends Resource implements HasUriTemplate
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

        $workspaces = rescue(fn () => $projectService->loadProjectWorkspaces($project->path));

        if (! $workspaces) {
            return Response::error('Failed to load project workspaces.');
        }

        return $this->json($workspaces->map(fn (WorkspaceData $data) => $data->toMcpResource())->values()->all());
    }

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('laborforest://projects/{uuid}/workspaces');
    }
}
