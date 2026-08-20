<?php

namespace App\Concerns\Mcp;

use App\Data\ProjectData;
use App\Data\WorkspaceData;
use App\Services\ProjectsService;
use Laravel\Mcp\Response;
use Throwable;

/**
 * Shared workspace lookup for the tools that act on a workspace directory.
 */
trait ResolvesWorkspace
{
    /**
     * Resolve a workspace and the project owning it, or the error response to return instead.
     *
     * @return array{ProjectData, WorkspaceData}|Response
     */
    protected function resolveWorkspace(string $path): array|Response
    {
        $projectsService = app(ProjectsService::class);

        try {
            $workspace = $projectsService->loadProjectWorkspace($path);
            $project = $projectsService->loadProjectFromWorkspace($workspace->path);
        } catch (Throwable $th) {
            return Response::error($th->getMessage());
        }

        if (! $project) {
            return Response::error('Failed to find workspace project.');
        }

        return [$project, $workspace];
    }
}
