<?php

namespace App\Concerns\Mcp;

use App\Data\ProjectData;
use App\Services\ProjectsService;
use Illuminate\Support\Str;
use Laravel\Mcp\Response;
use Throwable;

/**
 * Shared project lookup for the tools that identify a project by either its path or its UUID.
 */
trait ResolvesProject
{
    /**
     * Resolve a project by path when one is given, falling back to the UUID.
     *
     * The path is matched exactly, minus a trailing separator, because a project path is stored
     * verbatim and an agent reading one off a shell prompt tends to bring the slash with it.
     *
     * @return ProjectData|Response the project, or the error response to return instead
     */
    protected function resolveProject(?string $path, ?string $uuid): ProjectData|Response
    {
        $projectsService = app(ProjectsService::class);

        try {
            if (! empty($path)) {
                $path = Str::rtrim($path, '/');

                /** @var ProjectData|null $project */
                $project = $projectsService
                    ->loadProjects()
                    ->firstWhere(fn (ProjectData $data) => $data->path === $path);

                if (! $project) {
                    return Response::error('Failed to find project.');
                }

                return $project;
            }

            return $projectsService->loadProject($uuid);
        } catch (Throwable $th) {
            return Response::error($th->getMessage());
        }
    }
}
