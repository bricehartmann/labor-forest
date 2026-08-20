<?php

namespace App\Mcp\Tools;

use App\Concerns\Mcp\ResolvesWorkspace;
use App\Events\GlobalRefresh;
use App\Services\ProjectsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('add-workspace-example-workflows')]
#[Title('Add Workspace Example Workflows')]
#[Description('Add example workflows for a workspace.')]
class AddWorkspaceExampleWorkflowsTool extends Tool
{
    use ResolvesWorkspace;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $exampleWorkflows = app(ProjectsService::class)->listExampleWorkflowPaths()
            ->mapWithKeys(fn (string $path) => [basename($path) => $path])
            ->all();

        $validated = $request->validate([
            'path' => 'required',
            'example' => ['required', Rule::in(array_keys($exampleWorkflows))],
        ]);

        $resolved = $this->resolveWorkspace($validated['path']);

        if ($resolved instanceof Response) {
            return $resolved;
        }

        try {
            app(ProjectsService::class)->initializeWorkspaceStarterWorkflows($validated['path'], $exampleWorkflows[$validated['example']]);
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
        $exampleWorkflows = app(ProjectsService::class)->listExampleWorkflowPaths()
            ->map(fn (string $path) => basename($path))
            ->join(', ');

        return [
            'path' => $schema
                ->string()
                ->description('The full directory path to a workspace.')
                ->required(),
            'example' => $schema
                ->string()
                ->description(sprintf('Which example workflows to add. Must be one of [%s].', $exampleWorkflows))
                ->required(),
        ];
    }
}
