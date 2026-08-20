<?php

namespace App\Mcp\Tools;

use App\Data\WorkspaceData;
use App\Services\LaunchService;
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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[IsReadOnly]
#[Name('launch-terminal')]
#[Title('Launch Terminal')]
#[Description('Launch a terminal for the given workspace path using the preconfigured command.')]
class LaunchTerminalTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $path = Str::rtrim($request->get('path'), '/');

        $projectService = app(ProjectsService::class);

        /** @var WorkspaceData|null $workspace */
        $workspace = rescue(fn () => $projectService->loadProjectWorkspace($path));

        if (! $workspace) {
            return Response::error('Failed to find workspace.');
        }

        $project = rescue(fn () => $projectService->loadProjectFromWorkspace($workspace->path));

        if (! $project) {
            return Response::error('Failed to find workspace project.');
        }

        try {
            app(LaunchService::class)->launchTerminal($project, $workspace);
        } catch (Throwable $th) {
            return Response::error($th->getMessage());
        }

        return Response::text('success')->asAssistant();
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
                ->description('The full directory path to a workspace')
                ->required(),
        ];
    }
}
