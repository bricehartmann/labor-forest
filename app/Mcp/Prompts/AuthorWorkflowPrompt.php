<?php

namespace App\Mcp\Prompts;

use App\Enums\Directory;
use App\Enums\FileExtension;
use App\Enums\McpUri;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('author-workflow')]
#[Title('Author Workflow')]
#[Description('Write a new workflow file for a workspace from a description of what it should do. Produces the YAML and validates it; it does not run anything, and it does not decide for you whether the workflow is the right one to have.')]
class AuthorWorkflowPrompt extends Prompt
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
            'goal' => ['required', 'string'],
            'name' => ['nullable', 'string'],
        ], [
            'path.*' => 'Give the full directory path of the workspace the workflow belongs to, for example /Users/you/code/my-app-feature-x.',
            'goal.*' => 'Describe what the workflow should do, for example "bring the workspace up: copy the .env, install dependencies and migrate".',
        ]);

        $path = Str::rtrim($validated['path'], '/');
        $name = $validated['name'] ?? null;
        $directory = Directory::BASE->value.'/'.Directory::WORKFLOWS->value;

        $guidance = <<<GUIDANCE
        You are writing one workflow file for the LaborForest workspace at {$path}. Nothing here runs it.

        1. Read the `{$this->schemaUri()}` resource first. It is the grammar: the keys a workflow may
           carry, the three step types, which fields expand `{{ }}` variables, and how a step is
           executed. Do not write YAML from memory — several of its rules are not guessable.
        2. Read `{$this->templateVariablesUri()}` for the variables a `{{ }}` may name.
        3. Look at what is already in {$path}/{$directory}. An existing workflow shows the
           conventions this workspace already follows, and tells you whether the one you are about
           to write should be a step of an existing workflow instead of a new file.
        4. Inspect the repository itself before choosing commands. Use the package manager, task
           runner and services the project actually has, rather than the ones its ecosystem usually
           has.
        5. Write the file to {$path}/{$directory}/<name>.{$this->extension()}, using your own file
           tools — no tool on this server writes workflow files.
        6. Call `validate-workflow` with the workspace path and the workflow name. Fix whatever it
           reports and validate again until it passes.
        7. Report the file you wrote, what each step does, and the `require_status` / `ending_status`
           you chose and why.

        Hold to these while drafting:

        - Make every step safe to run twice. Guard a step that creates something with `unless`, and a
          step that removes something with `if`, so re-running the workflow is not an error.
        - Put anything specific to this one workspace — a database name, a URL, a bucket — into an
          `update_env` step written from `{{ }}` variables, rather than hard-coding it into commands.
        - The Project's primary directory is a Workspace too, and runs these same workflows. Gate any
          step that only makes sense in a worktree — copying `.env` in from the primary directory,
          rewriting `.env` keys to workspace-specific values — with
          `if: 'test "{{ WORKSPACE_DIR }}" != "{{ PROJECT_PRIMARY_DIR }}"'`, so the workflow is still
          safe to run in the primary directory.
        - `require_status` and `ending_status` are what make a workspace lifecycle reversible. A
          workflow that sets a workspace up should require `suspended` and end `ready`; one that tears
          it down should require `ready` and end `suspended`; one that is merely operational should
          require `ready` and end `ready` so it can be run again.
        - Do not run the workflow to test it. `validate-workflow` is the check; starting a run is the
          user's decision, and a workflow that sets up or tears down an environment has real effects.
        GUIDANCE;

        $task = $name === null
            ? "Write a workflow for the workspace at {$path} that does the following, and choose a file name for it:\n\n{$validated['goal']}"
            : "Write the workflow `{$name}` for the workspace at {$path}, which should do the following:\n\n{$validated['goal']}";

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
                description: 'The full directory path to the workspace the workflow belongs to.',
                required: true,
            ),
            new Argument(
                name: 'goal',
                description: 'What the workflow should do, in your own words.',
                required: true,
            ),
            new Argument(
                name: 'name',
                description: 'The name to give the workflow, with no file extension. Leave it out to have one chosen.',
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

    private function extension(): string
    {
        return FileExtension::YAML->value;
    }
}
