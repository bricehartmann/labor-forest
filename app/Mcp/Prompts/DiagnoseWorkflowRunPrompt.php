<?php

namespace App\Mcp\Prompts;

use App\Enums\Directory;
use App\Enums\File;
use App\Enums\McpUri;
use App\Enums\WorkflowStatus;
use App\Enums\WorkspaceStatus;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('diagnose-workflow-run')]
#[Title('Diagnose Workflow Run')]
#[Description('Work out why a workflow run failed, by reading the run logs a workspace keeps on disk. No tool on this server reports on a run, so this reads the log files directly. It diagnoses and proposes a fix; it does not re-run anything.')]
class DiagnoseWorkflowRunPrompt extends Prompt
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
            'workflow' => ['nullable', 'string'],
        ], [
            'path.*' => 'Give the full directory path of the workspace whose run failed, for example /Users/you/code/my-app-feature-x.',
        ]);

        $path = Str::rtrim($validated['path'], '/');
        $workflow = $validated['workflow'] ?? null;

        $logsDirectory = implode('/', [Directory::BASE->value, Directory::IGNORED->value, Directory::LOGS->value]);
        $statusFile = implode('/', [Directory::BASE->value, Directory::IGNORED->value, File::STATUS->value]);
        $workflowsDirectory = Directory::BASE->value.'/'.Directory::WORKFLOWS->value;

        $guidance = <<<GUIDANCE
        You are diagnosing a failed workflow run in the LaborForest workspace at {$path}.

        The run logs are files on disk, not something a tool here returns. Read them with your own
        file tools.

        1. List {$path}/{$logsDirectory}. One file per run, named
           `<UTC timestamp>_<workspace slug>_<workflow>`, so sorting the file names sorts the runs by
           time and the newest run is the last one.
        2. Open the newest run that ended `{$this->failedStatus()}`. Its top level carries `id`,
           `name`, `parent`, `timestamp`, `status`, `exception` and `steps`.
        3. Find the failing step. Each step entry carries `name`, `type`, `if`, `unless`, `run`,
           `map`, `env`, `exitCode`, `output`, `skip_reason` and timestamps. The failing step is the
           one with a non-zero `exitCode`; the steps after it carry `skip_reason: aborted`, meaning
           they never ran rather than that they were skipped on purpose.
        4. Read that step's `output`. It is the raw interleaved stdout and stderr with ANSI escapes
           still in it, so read past the escape codes rather than treating them as content.
        5. If the failing step is of type `workflow`, its `log_id` names the child run's own log file
           in the same directory. Follow it — the real failure is in there, not in the parent.
        6. Read {$path}/{$statusFile} for the status the workspace is in now, and the workflow file in
           {$path}/{$workflowsDirectory} for what the step was meant to do. Consult the
           `{$this->schemaUri()}` resource when a step's grammar is what is in question.
        7. Report what failed and why. Then propose the fix, and if it is a change to the workflow
           file, make it and confirm it with `validate-workflow`.

        Two things to keep straight:

        - A run whose `status` is `{$this->pendingStatus()}` or `{$this->runningStatus()}` is a run in
          flight, not a failure. Its log is being written as you read it.
        - A failed run leaves the workspace in `{$this->errorStatus()}`, and nothing runs while it sits
          there. Once the cause is fixed and `validate-workflow` passes, clear it with
          `override-workspace-status`. Whether to start another run is the user's call, not yours.
        GUIDANCE;

        $task = $workflow === null
            ? "Diagnose the most recent failed workflow run in the workspace at {$path}."
            : "Diagnose the most recent failed run of the `{$workflow}` workflow in the workspace at {$path}.";

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
                description: 'The full directory path to the workspace whose run failed.',
                required: true,
            ),
            new Argument(
                name: 'workflow',
                description: 'The name of the workflow to diagnose, with no file extension. Leave it out to take the most recent failed run of any workflow.',
                required: false,
            ),
        ];
    }

    private function schemaUri(): string
    {
        return McpUri::WORKFLOW_SCHEMA->value;
    }

    private function failedStatus(): string
    {
        return WorkflowStatus::FAILED->value;
    }

    private function pendingStatus(): string
    {
        return WorkflowStatus::PENDING->value;
    }

    private function runningStatus(): string
    {
        return WorkflowStatus::RUNNING->value;
    }

    private function errorStatus(): string
    {
        return WorkspaceStatus::ERROR->value;
    }
}
