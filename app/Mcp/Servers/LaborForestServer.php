<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\ProjectResource;
use App\Mcp\Resources\ProjectsResource;
use App\Mcp\Resources\SettingsResource;
use App\Mcp\Resources\TemplateVariablesResource;
use App\Mcp\Resources\WorkspacesResource;
use App\Mcp\Tools\AddProjectTool;
use App\Mcp\Tools\AddWorkspaceExampleWorkflowsTool;
use App\Mcp\Tools\AddWorkspaceTool;
use App\Mcp\Tools\FindProjectByPathTool;
use App\Mcp\Tools\LaunchBrowserTool;
use App\Mcp\Tools\LaunchIdeTool;
use App\Mcp\Tools\LaunchTerminalTool;
use App\Mcp\Tools\PurgeWorkflowLogsTool;
use App\Mcp\Tools\RemoveProjectTool;
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
- **Workspace** — a git worktree of a Project, coupled to a branch, identified by its absolute path. Any
  directory other than the `$HOME` directory that contains a `.laborforest` directory is a Workspace,
  including the Project's own primary directory.
- **Workflow** — a YAML file the user writes, stored at `.laborforest/workflows/<name>.yaml` inside the
  Workspace it runs against. Always addressed by name with no extension, so `up` means `up.yaml`. Its
  steps run sequentially in the Workspace directory and are of three types: `shell`, `update_env`
  (rewrites keys in the Workspace's `.env`) and `workflow` (runs another workflow inline).

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
- Order matters. A Project must be registered before `add-workspace` will accept it, and a Workspace must
  exist before workflows can be seeded into it or run in it.

## Running workflows

- `run-workflow` **queues the run and returns immediately** with the run log ID. It does not wait for the
  workflow to finish and it never returns step output. Do not report its reply as a completed run; the
  user watches progress in the app, and no tool here reports on a run in flight.
- Every step of the workflow runs. Selecting a subset of steps is possible in the app but not over MCP.
- A run is gated on the Workspace's `status`. Only `ready` and `suspended` may run anything at all;
  `pending`, `working`, `error` and `unknown` run nothing. A workflow declaring `require_status` needs
  the Workspace to hold exactly that status. A refusal reads, for example, `Workflow [up] requires the
  workspace to be suspended, but it is ready.` Clearing an `error` or `unknown` status is something only
  the user can do, from the app.
- While a run is going the status is `working`. Afterwards it is `error` if any step failed, otherwise
  the workflow's `ending_status`, otherwise whatever the Workspace held before the run.
- `validate-workflow` is how to check a workflow without consequences: it parses the file and reports its
  steps, and starts nothing. It reads the file alone and never the Workspace, so it cannot tell you
  whether the status gate would let a run through, nor whether an `ENV_` tag resolves right now.
- Steps run with LaborForest's own environment stripped out, under the workflow step timeout from the
  settings, which applies to each spawned process rather than to the run as a whole.

## Constraints

- Every tool acts on this machine as the logged-in user, with that user's shell and git credentials.
  Tools create and delete directories, remove worktrees, and run whatever shell commands the user's
  workflows contain. **Nothing on the LaborForest side asks the user to confirm a tool call.** Confirm
  `remove-project`, `run-workflow` and `purge-workflow-logs` with the user before calling them.
- The server is local only, bound to `127.0.0.1`, and runs only while the LaborForest app is open. A
  connection that stops answering usually means the app was quit or MCP was switched off.
- `update-settings` cannot change `mcp_enabled` or `mcp_port`. The server will not move or switch itself
  off underneath its own client; send the user to the app's Settings screen for those.
- For the three launch commands, in both `update-settings` and `update-project-launch-commands`: omitting
  a field or passing `null` keeps the stored value, a string sets it, and an empty string clears it. A
  Project's override wins over the global command, and clearing an override falls back to the global one.
  A command naming a variable LaborForest does not recognize is rejected.
- A tool that fails answers with the underlying message as an MCP error rather than raising. The message
  is usually actionable without asking the user.
- This server exposes tools and resources only. There are no prompts and no interactive MCP apps.
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
        AddWorkspaceExampleWorkflowsTool::class,
        ValidateWorkflowTool::class,
        RunWorkflowTool::class,
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
    ];

    protected array $prompts = [
        //
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
