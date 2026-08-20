<?php

namespace App\Mcp\Prompts;

use App\Enums\Directory;
use App\Enums\McpUri;
use App\Enums\WorkflowStepType;
use App\Enums\WorkspaceStatus;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('convert-setup-to-workflow')]
#[Title('Convert Setup to Workflow')]
#[Description('Turn a project\'s existing setup instructions — a README section, a Makefile, package scripts, a compose file — into the workflows that reproduce them in a workspace. Writes and validates the files; it does not run them, and it does not change the source it read.')]
class ConvertSetupToWorkflowPrompt extends Prompt
{
    /**
     * Handle the prompt request.
     *
     * @return array<int, Response>
     */
    public function handle(Request $request): array
    {
        $validated = $request->validate([
            'path' => ['required', 'string'],
            'source' => ['nullable', 'string'],
        ], [
            'path.*' => 'Give the full directory path of the workspace to write the workflows into, for example /Users/you/code/my-app-feature-x.',
        ]);

        $path = Str::rtrim($validated['path'], '/');
        $source = $validated['source'] ?? null;
        $directory = Directory::BASE->value.'/'.Directory::WORKFLOWS->value;

        $ready = WorkspaceStatus::READY->value;
        $suspended = WorkspaceStatus::SUSPENDED->value;

        $guidance = <<<GUIDANCE
        You are converting the existing setup steps of the project in {$path} into LaborForest
        workflows. Nothing here runs them.

        1. Read the `{$this->schemaUri()}` resource first, and `{$this->templateVariablesUri()}` for
           the variables a `{{ }}` may name. The grammar has rules that are not guessable.
        2. Find the setup steps. Look at the README, a Makefile or Justfile, the scripts of a package
           manifest, a compose file, and any `bin/setup`-style script. Prefer what the project
           actually documents over what its ecosystem usually does.
        3. Read what is already in {$path}/{$directory}, so you extend the workflows that exist
           rather than writing a second one that does the same job.
        4. Split the steps into workflows by what they do to the workspace, not by what file you
           found them in:
           - **up** — requires `{$suspended}`, ends `{$ready}`. Everything that takes a fresh worktree
             to a working one.
           - **down** — requires `{$ready}`, ends `{$suspended}`. The reverse of each thing `up`
             created, so a workspace can be put away and brought back.
           - **operational** — requires `{$ready}`, ends `{$ready}`. Anything run against a working
             workspace, like reseeding a database or clearing a queue, so it can be run repeatedly.
           A tail shared by more than one workflow belongs in its own file, called from a
           `{$this->workflowStepType()}` step rather than copied.
        5. While converting:
           - An instruction that says "edit your .env and set X" becomes an
             `{$this->updateEnvStepType()}` step, never a hard-coded value in a shell command. This is
             what keeps two workspaces of one project from sharing a database or a URL.
           - A per-workspace name — a database, a URL, a bucket, a cache prefix — comes from a `{{ }}`
             variable, so each worktree gets its own.
           - "Skip this if you already have X" becomes an `if` or `unless` gate, so the workflow can
             be run twice without failing.
           - Drop the steps that only made sense once, like cloning the repository.
           - An interactive step has no workflow equivalent. Convert it to its non-interactive flag if
             the tool has one, and otherwise say so rather than writing a step that will hang.
        6. Write each file to {$path}/{$directory}/<name>.yaml with your own file tools, then call
           `validate-workflow` on each and fix what it reports.
        7. Report the workflows you wrote, which source each step came from, and anything in the
           setup instructions you could not convert.

        Do not run any of them. `validate-workflow` is the check; starting a run is the user's call.
        GUIDANCE;

        $task = $source === null
            ? "Convert the setup instructions of the project at {$path} into workflows."
            : "Convert the setup instructions of the project at {$path} into workflows, starting from {$source}.";

        return [
            Response::text($guidance)->asAssistant(),
            Response::text($task),
        ];
    }

    /**
     * Get the prompt's arguments.
     *
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'path',
                description: 'The full directory path to the workspace to write the workflows into.',
                required: true,
            ),
            new Argument(
                name: 'source',
                description: 'Where the setup steps are written, such as a README section, a Makefile or a script. Leave it out to have them found.',
                required: false,
            ),
        ];
    }

    private function schemaUri(): string
    {
        return McpUri::WORKFLOW_SCHEMA->value;
    }

    private function templateVariablesUri(): string
    {
        return McpUri::TEMPLATE_VARIABLES->value;
    }

    private function workflowStepType(): string
    {
        return WorkflowStepType::WORKFLOW->value;
    }

    private function updateEnvStepType(): string
    {
        return WorkflowStepType::UPDATE_ENV->value;
    }
}
