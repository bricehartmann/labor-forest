<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\AuthorWorkflowPrompt;
use App\Mcp\Prompts\ConvertSetupToWorkflowPrompt;
use App\Mcp\Prompts\DiagnoseWorkflowRunPrompt;
use App\Mcp\Resources\ProjectResource;
use App\Mcp\Resources\ProjectsResource;
use App\Mcp\Resources\SettingsResource;
use App\Mcp\Resources\TemplateVariablesResource;
use App\Mcp\Resources\WorkflowSchemaResource;
use App\Mcp\Resources\WorkspacesResource;
use App\Mcp\Tools\AddProjectTool;
use App\Mcp\Tools\AddWorkspaceExampleWorkflowsTool;
use App\Mcp\Tools\AddWorkspaceTool;
use App\Mcp\Tools\FindProjectByPathTool;
use App\Mcp\Tools\LaunchBrowserTool;
use App\Mcp\Tools\LaunchIdeTool;
use App\Mcp\Tools\LaunchTerminalTool;
use App\Mcp\Tools\OverrideWorkspaceStatusTool;
use App\Mcp\Tools\PurgeWorkflowLogsTool;
use App\Mcp\Tools\RemoveProjectTool;
use App\Mcp\Tools\RemoveWorkspaceTool;
use App\Mcp\Tools\RunWorkflowTool;
use App\Mcp\Tools\UpdateProjectLaunchCommandsTool;
use App\Mcp\Tools\UpdateSettingsTool;
use App\Mcp\Tools\ValidateWorkflowTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Contracts\Transport;

#[Name('LaborForest')]
#[Instructions(<<<'INSTRUCTIONS'
LaborForest is a macOS desktop app for managing the git worktrees of your local repositories and running
local workflows inside them. It exists to set up, tear down, and otherwise methodically modify local
development environments. Everything it does happens on this machine, against the user's own working
copies. It is not CI, and it is not a way to manage agents.

## Vocabulary

- **Project** — a local git repository registered with LaborForest, identified by its absolute `path` or
  by its `uuid`. A repository on disk is not a Project until `add-project` registers it.
- **Workspace** — a git worktree of a Project, coupled to a branch, identified by the absolute path of
  the worktree's own root directory rather than of a directory inside it, and including the Project's
  primary directory. LaborForest keeps a Workspace's own files in a `.laborforest` directory inside it,
  but that directory is not what makes one: a path is a Workspace here only if it is a worktree of a
  registered Project, which is what every tool taking a `path` resolves it against.
- **Workflow** — a YAML file the user writes, stored at `.laborforest/workflows/<name>.yaml` inside the
  Workspace it runs against. Always addressed by name with no extension, so `up` means `up.yaml`, and
  a `.yml` file is read just the same. Its steps run sequentially in the Workspace directory and are
  of three types: `shell`, `update_env` (rewrites keys in the Workspace's `.env`) and `workflow`
  (runs another workflow inline).

## Orienting yourself

- Already working inside a directory? Call `find-project-by-path` with it. The match is on the exact
  Project path with a trailing `/` ignored, and the reply is a resource link carrying the `uuid` the
  other tools want. It matches a Project directory, not an arbitrary Workspace directory.
- Read `laborforest://projects` to enumerate Projects, and `laborforest://projects/{uuid}/workspaces` to
  see each Workspace's branch, `status` and `git_status`.
- Read `laborforest://settings` for the global configuration, and `laborforest://template-variables`
  before writing any `{{ }}` tag into a launch command. Tags also accept an `ENV_` prefix —
  `{{ ENV_APP_URL }}` reads `APP_URL` from the Workspace's own `.env` — which works everywhere the
  listed variables do but is deliberately absent from that list.
- Read `laborforest://workflow-schema` before writing or editing any workflow file. No tool here
  authors one — `add-workspace-example-workflows` only copies a fixed starter set — and several of
  the grammar's rules are not guessable from an example.
- Order matters. A Project must be registered before `add-workspace` will accept it, and a Workspace must
  exist before workflows can be seeded into it or run in it.

## Removing Workspaces

`remove-workspace` deletes a Workspace's worktree directory from disk, and its branch along with it when
asked. It is addressed by the Workspace path, and all three of `force_delete_worktree`, `delete_branch`
and `force_delete_branch` are stated on every call rather than defaulted — leaving one out is refused,
because an unstated flag read as false decides a destruction nobody answered either way.
A true `force_delete_branch` needs `delete_branch` to be true, and is refused beside a false one rather
than quietly ignored, which is what git does with that pairing; a false one is how a branch is kept and
is accepted on every call.

Only a Workspace at rest may be removed — `suspended`, `error` or `unknown`. The primary Workspace is the
Project's own directory and is never removable. A `ready` Workspace is refused until
`override-workspace-status` suspends it, and one whose run is still in flight — `pending` or `working` — is
refused until the run finishes, because the run is executing in the very directory the removal would
delete. A worktree with local changes is refused by git itself, which is what `force_delete_worktree`
overrides. That gate is this tool's own: `remove-project` with `remove_worktrees: true` force-removes
every linked worktree of the Project without reading a single status, in-flight run included, so it is
not a way round a refusal here.

The worktree is removed before the branch, so the two can part company. `git branch --delete` refuses a
branch that is not fully merged, and a `delete_branch: true` call against one answers with that refusal
once the directory is already gone — the message says nothing about the worktree. Do not retry: the
Workspace no longer resolves, so the second call is `not found`, and no tool here deletes a branch on its
own. Tell the user to delete it by hand in the repository, or pass `force_delete_branch: true` in the
first place when the branch is known to be unmerged.

Nothing records a Workspace outside the git worktree, so a removal leaves nothing to clean up
afterwards — and nothing to undo it with either.

## Running workflows

- `run-workflow` **queues the run and returns immediately** with the run log ID. It does not wait for the
  workflow to finish and it never returns step output. Do not report its reply as a completed run; the
  user watches progress in the app, and no tool here reports on a run in flight.
- Every step of the workflow runs. Selecting a subset of steps is possible in the app but not over MCP.
- A run is gated on the Workspace's `status`. Only `ready` and `suspended` may run anything at all;
  `pending`, `working`, `error` and `unknown` run nothing. A workflow declaring `require_status` needs
  the Workspace to hold exactly that status. A refusal reads, for example, `Workflow [up] requires the
  workspace to be suspended, but it is ready.` Clear an `error` or `unknown` status with
  `override-workspace-status`, which sets a Workspace to `ready` or `suspended` and is what makes runs
  possible again.
- While a run is going the status is `working`. Afterwards it is `error` if any step failed, otherwise
  the workflow's `ending_status`, otherwise whatever the Workspace held before the run. A Workspace whose
  run is still in flight — `pending` or `working` — cannot be overridden, because the finishing job writes
  its own final status over anything set underneath it.
- `validate-workflow` is how to check a workflow without consequences: it parses the file and reports its
  steps, and starts nothing. It reads the file alone and never the Workspace, so it cannot tell you
  whether the status gate would let a run through, nor whether an `ENV_` tag resolves right now.
- Steps run with LaborForest's own environment stripped out, under the workflow step timeout from the
  settings, which applies to each spawned process rather than to the run as a whole.

## Authoring workflows

Workflow files are written by hand, by the user or by you. No tool here authors or edits one — the
only tool that writes a workflow file at all is `add-workspace-example-workflows`, which copies a
fixed starter set into a workspace that has none. Anything else is written with whatever file tools
the client has, against the grammar in `laborforest://workflow-schema`, and then checked with
`validate-workflow`.

Three prompts cover the jobs that take more than a tool call: `author-workflow` writes one workflow
from a description of what it should do, `convert-setup-to-workflow` turns a project's existing setup
instructions into the workflows that reproduce them, and `diagnose-workflow-run` works out why a run
failed by reading the run logs the Workspace keeps at `.laborforest/ignored/logs/`. Those logs are
files on disk; nothing here serves them, and nothing here reports on a run in flight.

## Recovering a failed run

A run that failed leaves the Workspace in `error`, and every further run is refused while it sits there.
Recover it in this order: read the run log in `.laborforest/ignored/logs/`, which the
`diagnose-workflow-run` prompt walks you through; fix the cause, usually the workflow file, and confirm the
fix with `validate-workflow`; call `override-workspace-status` to put the Workspace back to the status the
workflow needs; and only then `run-workflow` again. Clearing the status without fixing the cause just
reproduces the failure.

## Constraints

- Every tool acts on this machine as the logged-in user, with that user's shell and git credentials.
  Tools create and delete directories, remove worktrees, and run whatever shell commands the user's
  workflows contain. Only the four tools that spawn a shell — `run-workflow`, `launch-terminal`,
  `launch-ide` and `launch-browser` — are gated on the LaborForest side;
  **nothing else here asks the user to confirm a tool call.**
  Confirm `remove-project`, `remove-workspace`, `run-workflow` and `purge-workflow-logs` with the user
  before calling them.
- Those four obey the user's shell command execution policy. Under `allow` they run as called. Under
  `require-approval` the call changes nothing and answers `pending manual approval in application UI`:
  LaborForest shows the user the workflow or the command and waits, so treat the work as neither done
  nor refused, tell the user to approve it in the app, and do not call the tool again to retry. Under
  `deny` those four are not published at all, exactly as in read-only mode below — a workflow cannot
  be run and nothing can be launched from here until the user changes the policy on the Settings
  screen.
- The server is local only, bound to `127.0.0.1`, requires the bearer token the user copied out of the
  Settings screen, and runs only while the LaborForest app is open. A connection that stops answering
  usually means the app was quit or MCP was switched off; one that starts refusing usually means the
  token was regenerated.
- The user can put the server in read-only mode, in which only `find-project-by-path` and
  `validate-workflow` are published. A settings file LaborForest cannot read is answered the same
  way, as is a `deny` shell policy for the four tools above. A tool named here that is missing from
  the tool list is not a bug to work around, and no other tool substitutes for it: say which tool is
  missing, and send the user to the Settings screen.
- `update-settings` cannot change `mcp_enabled`, `mcp_port`, `mcp_read_only`, `mcp_shell_policy`,
  `headless` or the token. The server will not move, unlock or switch itself off underneath its own
  client, will not loosen its own shell gate, and does not decide when the app shows its window; send
  the user to the app's Settings screen for those. The first five are readable on
  `laborforest://settings`; the token is never returned over MCP at all. Passing any of the six to
  `update-settings` is ignored rather than refused, so do not report one as changed.
- For the three launch commands, in both `update-settings` and `update-project-launch-commands`: omitting
  a field or passing `null` keeps the stored value, a string sets it, and an empty string clears it. A
  Project's override wins over the global command, and clearing an override falls back to the global one.
  A command naming a variable LaborForest does not recognize is rejected.
- A tool that fails answers with the underlying message as an MCP error rather than raising. The message
  is usually actionable without asking the user.
- This server exposes tools, resources and prompts. There are no interactive MCP apps. A prompt only
  hands back instructions for you to carry out; invoking one changes nothing on the machine.
INSTRUCTIONS)]
class LaborForestServer extends Server
{
    protected array $tools = [
        FindProjectByPathTool::class,
        LaunchIdeTool::class,
        LaunchTerminalTool::class,
        LaunchBrowserTool::class,
        AddProjectTool::class,
        RemoveProjectTool::class,
        AddWorkspaceTool::class,
        RemoveWorkspaceTool::class,
        AddWorkspaceExampleWorkflowsTool::class,
        ValidateWorkflowTool::class,
        RunWorkflowTool::class,
        OverrideWorkspaceStatusTool::class,
        UpdateSettingsTool::class,
        UpdateProjectLaunchCommandsTool::class,
        PurgeWorkflowLogsTool::class,
    ];

    protected array $resources = [
        SettingsResource::class,
        ProjectsResource::class,
        ProjectResource::class,
        WorkspacesResource::class,
        TemplateVariablesResource::class,
        WorkflowSchemaResource::class,
    ];

    protected array $prompts = [
        AuthorWorkflowPrompt::class,
        ConvertSetupToWorkflowPrompt::class,
        DiagnoseWorkflowRunPrompt::class,
    ];

    /**
     * The version is set here rather than returned from a method, because the package reads the
     * property (or a #[Version] attribute) when it builds the server context and never asks for it.
     */
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);

        $this->version = config('nativephp.version') ?? 'main';
    }
}
