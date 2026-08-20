<?php

namespace App\Mcp\Tools;

use App\Data\ProjectData;
use App\Enums\McpUri;
use App\Services\ProjectsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('find-project-by-path')]
#[Title('Find Project by Path')]
#[Description('Find an individual project by full directory path')]
class FindProjectByPathTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $path = Str::rtrim($request->get('path'), '/');

        /** @var ProjectData|null $project */
        $project = rescue(fn () => app(ProjectsService::class)
            ->loadProjects()
            ->firstWhere(fn (ProjectData $data) => $data->path === $path));

        if (! $project) {
            return Response::error('Failed to find project.');
        }

        return Response::resourceLink(
            uri: McpUri::PROJECT->build(['uuid' => $project->uuid]),
            name: $project->slugKebab(),
            mimeType: 'application/json',
            title: $project->dirName(),
            description: $project->path,
        );
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema
                ->string()
                ->description('The full directory path to a project')
                ->required(),
        ];
    }
}
