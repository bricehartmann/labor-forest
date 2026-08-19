<?php

namespace App\Mcp\Resources;

use App\Concerns\Mcp\RespondsWithJson;
use App\Data\ProjectData;
use App\Services\ProjectsService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('projects')]
#[Title('Projects')]
#[Description('The list of configured projects.')]
#[Uri('laborforest://projects')]
#[MimeType('application/json')]
class ProjectsResource extends Resource
{
    use RespondsWithJson;

    /**
     * Handle the resource request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $projects = rescue(fn () => app(ProjectsService::class)->loadProjects());

        if (! $projects) {
            return Response::error('Failed to load projects.');
        }

        return $this->json($projects->map(fn (ProjectData $data) => $data->toMcpResource())->values()->all());
    }
}
