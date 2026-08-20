<?php

namespace App\Mcp\Tools;

use App\Concerns\Mcp\ResolvesProject;
use App\Events\GlobalRefresh;
use App\Services\GitService;
use App\Services\ProjectsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('add-workspace')]
#[Title('Add Workspace')]
#[Description('Add a new workspace for an existing project for either a new or existing git branch.')]
class AddWorkspaceTool extends Tool
{
    use ResolvesProject;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'path' => 'required_without:uuid',
            'uuid' => 'required_without:path',
            'branch' => 'required',
            'base_branch' => 'nullable',
        ]);

        $project = $this->resolveProject($validated['path'] ?? null, $validated['uuid'] ?? null);

        if ($project instanceof Response) {
            return $project;
        }

        try {
            $branchExists = app(GitService::class)->doesBranchExist($project->path, $validated['branch']);
        } catch (Throwable $th) {
            return Response::error($th->getMessage());
        }

        if (! $branchExists) {
            $request->validate([
                'base_branch' => 'required',
            ]);
        }

        try {
            app(ProjectsService::class)->addProjectWorkspace(
                $project,
                $validated['branch'],
                $validated['base_branch'] ?? null,
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
            'path' => $schema
                ->string()
                ->description('The full directory path to a project. Required without `uuid`.')
                ->nullable(),
            'uuid' => $schema
                ->string()
                ->description('The project UUID. Required without `path`.')
                ->nullable(),
            'branch' => $schema
                ->string()
                ->description('The new or existing git branch.')
                ->required(),
            'base_branch' => $schema
                ->string()
                ->description('The base branch for a new branch.')
                ->nullable(),
        ];
    }
}
