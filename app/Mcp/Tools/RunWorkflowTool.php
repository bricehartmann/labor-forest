<?php

namespace App\Mcp\Tools;

use App\Concerns\Mcp\ResolvesWorkspace;
use App\Events\GlobalRefresh;
use App\Services\SettingsService;
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
use Throwable;

#[Name('run-workflow')]
#[Title('Run Workflow')]
#[Description('Dispatch a local workflow to run within a workspace by path. Every step of the workflow runs. Returns the unique workflow ID on success.')]
class RunWorkflowTool extends Tool
{
    use ResolvesWorkspace;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $resolved = $this->resolveWorkspace(Str::rtrim($request->get('path'), '/'));

        if ($resolved instanceof Response) {
            return $resolved;
        }

        [$project, $workspace] = $resolved;

        $workflowName = $request->get('workflow');
        $workflowService = app(WorkflowService::class);

        // reported before the file is loaded, so a name that matches nothing reads as a missing
        // workflow rather than as a parse failure
        if (! File::isFile($workflowService->workflowPath($workspace->path, $workflowName))) {
            return Response::error("Workflow '{$workflowName}' does not exist.");
        }

        try {
            $workflowRunLogId = $workflowService->dispatchWorkflow(
                projectUuid: $project->uuid,
                workspacePath: $workspace->path,
                workflowName: $workflowName,
                stepHashes: null,
                parentLogId: null,
                timeoutSeconds: app(SettingsService::class)->loadSettings()->workflow_step_timeout_seconds,
            );
        } catch (Throwable $th) {
            return Response::error($th->getMessage());
        }

        broadcast(new GlobalRefresh);

        return Response::text($workflowRunLogId)->asAssistant();
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
            'workflow' => $schema
                ->string()
                ->description('The name of the workflow with no file extension')
                ->required(),
        ];
    }
}
