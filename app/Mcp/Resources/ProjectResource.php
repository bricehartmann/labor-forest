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
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Name('project')]
#[Title('Project')]
#[Description('Get an individual project by UUID.')]
#[MimeType('application/json')]
class ProjectResource extends Resource implements HasUriTemplate
{
    use RespondsWithJson;

    /**
     * Handle the resource request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        /** @var ProjectData|null $project */
        $project = rescue(fn () => app(ProjectsService::class)->loadProjects()->firstWhere('uuid', $request->get('uuid')));

        if (! $project) {
            return Response::error('Failed to load project.');
        }

        return $this->json($project->toMcpResource());
    }

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('laborforest://projects/{uuid}');
    }
}
