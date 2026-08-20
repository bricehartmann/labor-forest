<?php

namespace App\Mcp\Tools;

use App\Enums\McpUri;
use App\Events\GlobalRefresh;
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
use Throwable;

#[Name('add-project')]
#[Title('Add Project')]
#[Description('Add a new project by directory path. Must be a git repository.')]
class AddProjectTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $path = Str::rtrim($request->get('path'), '/');

        try {
            $project = app(ProjectsService::class)->addProject($path);
        } catch (Throwable $th) {
            return Response::error($th->getMessage());
        }

        broadcast(new GlobalRefresh);

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
                ->description('The full directory path to a new project')
                ->required(),
        ];
    }
}
