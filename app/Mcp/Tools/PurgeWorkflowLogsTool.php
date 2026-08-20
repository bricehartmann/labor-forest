<?php

namespace App\Mcp\Tools;

use App\Concerns\Mcp\RegistersWhenWritable;
use App\Concerns\Mcp\ResolvesWorkspace;
use App\Events\GlobalRefresh;
use App\Services\WorkflowService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Throwable;

#[IsDestructive]
#[Name('purge-workflow-logs')]
#[Title('Purge Workflow Logs')]
#[Description('Delete the run log records of a single workflow within a workspace by path. Runs that are still pending or running are left alone.')]
class PurgeWorkflowLogsTool extends Tool
{
    use RegistersWhenWritable;
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

        [, $workspace] = $resolved;

        $workflowName = $request->get('workflow');

        // deliberately not gated on the workflow file existing: run logs outlive the workflow that
        // wrote them, and a deleted workflow is exactly when its logs are worth purging
        try {
            ['purged' => $purged, 'skipped' => $skipped] = app(WorkflowService::class)
                ->purgeWorkflowLogs($workspace, $workflowName);
        } catch (Throwable $th) {
            return Response::error($th->getMessage());
        }

        broadcast(new GlobalRefresh);

        $message = "Purged {$purged} log records.";

        if ($skipped > 0) {
            $message .= " Skipped {$skipped} still in progress.";
        }

        return Response::text($message)->asAssistant();
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
                ->description('The name of the workflow with no file extension. A name matching no log records purges nothing and is not an error.')
                ->required(),
        ];
    }
}
