<?php

namespace App\Mcp\Tools;

use App\Concerns\Mcp\RegistersWhenWritable;
use App\Concerns\Mcp\ResolvesWorkspace;
use App\Events\GlobalRefresh;
use App\Services\GitService;
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
#[Name('remove-workspace')]
#[Title('Remove Workspace')]
#[Description('Remove a workspace by path, deleting its worktree directory and optionally its branch.')]
class RemoveWorkspaceTool extends Tool
{
    use RegistersWhenWritable;
    use ResolvesWorkspace;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        // Every flag is stated on every call: the schema marks all three required, but nothing
        // enforces a schema, and an omitted flag read as false would quietly decide a destruction
        // the agent never asked about either way. `declined_if` alone would not do it — it passes a
        // key that is absent, which is exactly how a forced branch delete could arrive without the
        // `delete_branch` that gives it effect, and git drops that pairing without a word.
        $request->validate([
            'path' => 'required|string',
            'force_delete_worktree' => 'required|boolean',
            'delete_branch' => 'required|boolean',
            'force_delete_branch' => 'required|boolean|declined_if:delete_branch,false',
        ], [
            'force_delete_branch.declined_if' => 'force_delete_branch requires delete_branch to be true.',
        ]);

        $resolved = $this->resolveWorkspace(Str::rtrim($request->get('path'), '/'));

        if ($resolved instanceof Response) {
            return $resolved;
        }

        [$project, $workspace] = $resolved;

        // the Remove action on the Project screen offers itself for exactly these workspaces, and
        // the gate is answered here rather than left to the hidden button, so a tool call cannot
        // reach past it — git would happily delete the directory a running workflow is working in
        if ($workspace->is_primary) {
            return Response::error("Workspace at path '{$workspace->path}' is the project's primary workspace and cannot be removed.")->asAssistant();
        }

        if (! $workspace->status->allowsRemoval()) {
            // an in-flight run has nothing to call and only has to be waited out; override-workspace-status
            // refuses one too, so naming it here would send the agent to a second refusal
            return Response::error($workspace->status->hasRunInFlight()
                ? "Workspace at path '{$workspace->path}' is '{$workspace->status->value}' and has a workflow run in flight. Remove it once the run has finished."
                : "Workspace at path '{$workspace->path}' is '{$workspace->status->value}' and cannot be removed. Call override-workspace-status to set it to 'suspended' first."
            )->asAssistant();
        }

        try {
            app(GitService::class)->removeWorktree(
                mainWorktreePath: $project->path,
                worktreePath: $workspace->path,
                branch: $workspace->branch,
                force: $request->boolean('force_delete_worktree'),
                deleteBranch: $request->boolean('delete_branch'),
                forceDeleteBranch: $request->boolean('force_delete_branch'),
            );
        } catch (Throwable $th) {
            return Response::error($th->getMessage())->asAssistant();
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
                ->description('The full directory path to a workspace.')
                ->required(),
            'force_delete_worktree' => $schema
                ->boolean()
                ->description('If the worktree should be removed even though it has local changes.')
                ->required(),
            'delete_branch' => $schema
                ->boolean()
                ->description("If the workspace's branch should be deleted along with the worktree.")
                ->required(),
            'force_delete_branch' => $schema
                ->boolean()
                ->description('If the branch should be deleted even though it is not fully merged. Requires `delete_branch`.')
                ->required(),
        ];
    }
}
