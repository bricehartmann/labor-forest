<?php

namespace App\Mcp\Tools;

use App\Concerns\Mcp\ResolvesWorkspace;
use App\Concerns\Mcp\RespondsWithJson;
use App\Data\WorkflowStepData;
use App\Services\WorkflowService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\File;
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
#[Name('validate-workflow')]
#[Title('Validate Workflow')]
#[Description('Check that a workflow of a workspace parses and is structurally valid, without running any of it. Reports on the workflow file alone, not on whether the workspace could run it now.')]
class ValidateWorkflowTool extends Tool
{
    use ResolvesWorkspace;
    use RespondsWithJson;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $resolved = $this->resolveWorkspace(Str::rtrim($request->get('path'), '/'));

        if ($resolved instanceof Response) {
            return $resolved;
        }

        [, $workspace] = $resolved;

        $workflowName = $request->get('workflow');
        $workflowService = app(WorkflowService::class);
        $workflowPath = $workflowService->workflowPath($workspace->path, $workflowName);

        // reported before the file is loaded, so a name that matches nothing reads as a missing
        // workflow rather than as a parse failure, exactly as run-workflow reports it
        if (! File::isFile($workflowPath)) {
            return Response::error("Workflow '{$workflowName}' does not exist.");
        }

        /**
         * The file's own rules are the whole of the check: they cover the shape of every step and
         * reject a `{{ }}` naming a variable LaborForest does not recognize. Nothing here reads the
         * workspace's state — whether an `ENV_` passthrough resolves, or whether the workspace holds
         * the status the workflow requires, are questions about this moment rather than about the
         * file, and a workflow that is valid must validate the same way in every workspace.
         */
        try {
            $workflow = $workflowService->loadWorkflow($workflowPath);
        } catch (Throwable $th) {
            return Response::error($th->getMessage());
        }

        return $this->json([
            'workflow' => $workflowName,
            'path' => $workflowPath,
            'require_status' => $workflow->require_status?->value,
            'ending_status' => $workflow->ending_status?->value,
            'steps' => $workflow->steps
                ->values()
                ->map(fn (WorkflowStepData $step) => ['name' => $step->name, 'type' => $step->type->value])
                ->all(),
        ]);
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
                ->description('The full directory path to a workspace.')
                ->required(),
            'workflow' => $schema
                ->string()
                ->description('The name of the workflow with no file extension.')
                ->required(),
        ];
    }
}
