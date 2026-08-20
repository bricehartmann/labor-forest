<?php

namespace App\Mcp\Tools;

use App\Concerns\Mcp\RegistersWhenWritable;
use App\Concerns\Mcp\ResolvesWorkspace;
use App\Enums\WorkspaceStatus;
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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Throwable;

#[IsIdempotent]
#[Name('override-workspace-status')]
#[Title('Override Workspace Status')]
#[Description('Set the status of a workspace by path, overriding the status it currently holds. Use it to clear the error status a failed workflow run leaves behind, so workflows can run in the workspace again.')]
class OverrideWorkspaceStatusTool extends Tool
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

        // a run in flight writes its own final status when it ends, so an override applied now is
        // overwritten moments later; worse, a workspace forced to ready mid-run accepts a second
        // concurrent run against the same worktree
        if ($workspace->status->hasRunInFlight()) {
            return Response::error("Workspace at path '{$workspace->path}' has a workflow run in flight and is '{$workspace->status->value}'. Override the status from the app once the run has finished.");
        }

        try {
            app(ProjectsService::class)->updateProjectWorkspaceStatus(
                $workspace->path,
                WorkspaceStatus::from($request->get('status')),
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
        $statuses = WorkspaceStatus::declarableInWorkflowValues();

        return [
            'path' => $schema
                ->string()
                ->description('The full directory path to a workspace.')
                ->required(),
            'status' => $schema
                ->string()
                ->enum($statuses)
                ->description(sprintf('The status to give the workspace. Must be one of [%s], the same two statuses the app offers. Clearing an error status is what this is usually for.', implode(', ', $statuses)))
                ->required(),
        ];
    }
}
