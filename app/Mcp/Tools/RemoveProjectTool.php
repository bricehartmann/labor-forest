<?php

namespace App\Mcp\Tools;

use App\Events\GlobalRefresh;
use App\Services\ProjectsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Throwable;

#[IsDestructive]
#[Name('remove-project')]
#[Title('Remove Project')]
#[Description('Remove a project by UUID.')]
class RemoveProjectTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        try {
            app(ProjectsService::class)->removeProject(
                $request->get('uuid'),
                $request->boolean('remove_directory'),
                $request->boolean('remove_worktrees'),
            );
        } catch (Throwable $th) {
            return Response::error($th->getMessage());
        }

        broadcast(new GlobalRefresh);

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
            'uuid' => $schema
                ->string()
                ->description('The project UUID')
                ->required(),
            'remove_directory' => $schema
                ->boolean()
                ->description('If the .laborforest directory should be removed')
                ->required(),
            'remove_worktrees' => $schema
                ->boolean()
                ->description('If the project worktrees should be removed')
                ->required(),
        ];
    }
}
