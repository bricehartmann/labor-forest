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

#[Name('workspace')]
#[Title('Workspace')]
#[Description('Get an individual workspace by kebab-slug for a project by UUID.')]
#[MimeType('application/json')]
class WorkspaceResource extends Resource implements HasUriTemplate
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

        return $this->json($workspace->toMcpResource());
    }

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('laborforest://projects/{uuid}/workspaces/{slugKebab}');
    }
}
