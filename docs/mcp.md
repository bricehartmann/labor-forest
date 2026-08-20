# MCP

## Overview

LaborForest can expose itself to AI agents over the [Model Context Protocol](https://modelcontextprotocol.io). An agent that connects to it can list your Projects and Workspaces, create and remove git worktrees, seed workflows into a Workspace, run those workflows, delete the run logs those workflows leave behind, open your IDE, terminal or browser against a Workspace, and change both the global settings and a single Project's launch command overrides.

The server is local. It listens on `127.0.0.1` only, it runs as a child process of the app for as long as the app is open, and it is enabled by default on port `9189`. The toggle, the port, the `Add to Claude Code` command and the `Test connection` button all live on the [Settings](settings.md) screen.

Connecting an agent to LaborForest gives that agent the ability to create and destroy directories on your machine and to run arbitrary shell commands through your workflows, under your own user account. Nothing asks you to confirm a tool call on the LaborForest side.

## Connecting

The endpoint is `http://127.0.0.1:9189/mcp/laborforest`, over the streamable HTTP transport. The port is whatever the [Settings](settings.md) screen is set to.

For Claude Code, the `Add to Claude Code` field on the Settings screen is the whole registration:

```shell
claude mcp add --transport http laborforest --scope user http://127.0.0.1:9189/mcp/laborforest
```

Any other client that speaks HTTP MCP connects to the same URL. There is no authentication of any kind in front of the route, and no token to configure. Any process on your machine that can reach the loopback port can call every tool, so the only thing keeping the server private is that it never leaves `127.0.0.1`.

The app window is served by its own process, which does refuse requests that do not come from the app itself. That guard is removed inside the MCP process alone, because no MCP client can satisfy it.

## Use cases

**Start work on a ticket.** The agent calls `add-workspace` with the Project path and a new branch name, `add-workspace-example-workflows` if the Project has no workflows yet, `run-workflow` with your `up` workflow to install dependencies and boot services, then `launch-ide` to put the new Workspace on screen. The agent never needs to know how your worktrees are laid out.

**Clean up when a branch merges.** The agent reads the `workspaces` resource to see which branches still have a Workspace and what state each is in, runs your `down` workflow against the ones that are finished, and calls `remove-project` when a whole repository is no longer wanted.

**Orient itself in a directory it is already sitting in.** An agent working inside a worktree calls `find-project-by-path` with the directory it was started in and gets back a link to the Project resource, which tells it the Project UUID it needs for the rest of the tools.

**Change configuration without leaving the conversation.** `update-settings` writes the global launch commands and the workflow step timeout, and `update-project-launch-commands` writes the overrides that one Project uses in place of them. The `template-variables` resource lists the mustache tags those commands accept.

**Reclaim what a busy Workspace has accumulated.** Every run of a workflow, and every child workflow it starts, leaves a log file behind. `purge-workflow-logs` deletes the logs of one workflow in one Workspace, leaving the runs that are still going.

## Resources

Every resource answers with `application/json`.

| Resource             | URI                                        | Contents                                                                |
|----------------------|--------------------------------------------|-------------------------------------------------------------------------|
| `settings`           | `laborforest://settings`                   | The whole settings file, including `mcp_enabled` and `mcp_port`         |
| `projects`           | `laborforest://projects`                   | Every configured Project, each carrying the `uri` that reads it         |
| `project`            | `laborforest://projects/{uuid}`            | One Project by UUID                                                     |
| `workspaces`         | `laborforest://projects/{uuid}/workspaces` | Every Workspace belonging to one Project                                |
| `template-variables` | `laborforest://template-variables`         | Each variable a command or workflow step accepts, with an example value |

A Project carries its `uuid`, `path`, `last_opened` timestamp and any launch command overrides, plus the derived names the variables are built from: `dir_name`, `parent_dir`, `slug_kebab` and `slug_snake`.

A Workspace carries `is_primary`, `path`, `branch`, `status` and `git_status`, plus the same derived names. `status` is the LaborForest Workspace status that decides which workflows may run, and is described in [Workflows](workflows.md).

The `projects` list is ordered by when each Project was last opened. It answers with an empty list rather than an error when no Projects are configured.

`template-variables` lists only the enumerated variables. The `ENV_` prefix, which reads a key out of the Workspace's own `.env` file, is not in that list but works everywhere the enumerated variables do. It is described in [Settings](settings.md).

## Tools

| Tool                              | Annotation  | Arguments                                                               | Returns                        |
|-----------------------------------|-------------|-------------------------------------------------------------------------|--------------------------------|
| `find-project-by-path`            | read-only   | `path`                                                                  | A link to the Project resource |
| `launch-ide`                      | read-only   | `path` to a Workspace                                                   | `success`                      |
| `launch-terminal`                 | read-only   | `path` to a Workspace                                                   | `success`                      |
| `launch-browser`                  | read-only   | `path` to a Workspace                                                   | `success`                      |
| `add-project`                     |             | `path`                                                                  | A link to the Project resource |
| `remove-project`                  | destructive | `uuid`, `remove_directory`, `remove_worktrees`                          | `success`                      |
| `add-workspace`                   |             | `path` or `uuid`, `branch`, `base_branch`                               | `success`                      |
| `add-workspace-example-workflows` |             | `path` to a Workspace, `example`                                        | `success`                      |
| `run-workflow`                    |             | `path` to a Workspace, `workflow`                                       | The workflow run log ID        |
| `update-settings`                 | destructive | `dark_mode`, `workflow_step_timeout_seconds`, the three launch commands | `success`                      |
| `update-project-launch-commands`  | destructive | `path` or `uuid`, the three launch commands                             | `success`                      |
| `purge-workflow-logs`             | destructive | `path` to a Workspace, `workflow`                                       | What it purged and skipped     |

The annotation is what the client is told about the tool before it calls it. A client that asks you to approve destructive tool calls will ask about `remove-project`, `update-settings`, `update-project-launch-commands` and `purge-workflow-logs`. The three launch tools are annotated read-only because they change nothing in LaborForest, even though they do start an application.

### Finding and adding Projects

`find-project-by-path` matches on the exact Project path, with a trailing `/` ignored. It reports `Failed to find project.` when nothing matches, which distinguishes a directory that is not a Project from a projects file that could not be read.

`add-project` takes the path of an existing git repository. The directory has to exist and has to be a repository already; the tool does not create either. It returns a link to the new Project resource, so the agent has the UUID immediately.

`remove-project` needs all three arguments. `remove_directory` decides whether the Project's `.laborforest` directory goes with it, and `remove_worktrees` decides whether the worktrees are removed from disk. Passing `false` to both removes the Project from LaborForest and leaves everything on disk untouched.

### Adding Workspaces

`add-workspace` identifies the Project by either `path` or `uuid`, and needs a `branch`. When the branch already exists in the repository, `base_branch` is ignored. When it does not, `base_branch` becomes required and the new branch is created from it. Adding a Workspace fails if the worktree directory it would create already exists.

`add-workspace-example-workflows` seeds one of the starter workflow sets shipped with the app into a Workspace that already exists. `example` must be one of `bare`, `javascript` or `laravel`, and the tool tells the client which names it accepts.

### Running workflows

`run-workflow` takes the Workspace path and the workflow name with no file extension, so `up` runs `.laborforest/workflows/up.yaml`. A name matching no file is reported as `Workflow 'up' does not exist.` before the file is read, so a missing workflow never reads as a parse failure.

The run is queued and the tool returns as soon as it is dispatched, with the run log ID as its result. It does not wait for the workflow to finish and it does not return the step output; the run is watched in the app, on the Workflow log screen. Every step runs — there is no way to run a subset of the steps over MCP, as there is when starting a workflow from the Project screen.

A workflow declaring `require_status` is gated exactly as it is in the app. Asking for one the Workspace is not in a position to run is refused with a message naming both statuses, for example `Workflow [up] requires the workspace to be suspended, but it is ready.` See [Workflows](workflows.md) for the statuses and the gate.

Steps run with the workflow step timeout from the settings file, and with LaborForest's own environment stripped out, so a workflow does not inherit the app's configuration.

### Purging run logs

`purge-workflow-logs` takes the Workspace path and a workflow name, and deletes the run logs that workflow has written in that Workspace. It matches on the workflow name recorded inside each log rather than on the file name, and it never touches another workflow's logs or another Workspace's.

Runs that are still pending or running are counted and left on disk, because the job holding one is still writing to it. The result says so: `Purged 4 log records. Skipped 1 still in progress.`, or `Purged 4 log records.` when nothing was skipped.

A name matching no log is `Purged 0 log records.` rather than an error, so calling it twice is safe. Unlike `run-workflow`, the tool does not check that the workflow file still exists — logs outlive the workflow that wrote them, which is exactly when purging them is worth doing.

### Changing settings

Every argument to `update-settings` is optional, and one that is omitted or `null` leaves the stored value alone. Passing an **empty string** to a launch command clears it instead, which is how a command is removed rather than replaced. The same three values are the rule for `update-project-launch-commands` below.

A launch command is validated the same way the Settings form validates it: a mustache tag naming a variable LaborForest does not recognize is rejected, with the unknown names listed.

`update-settings` cannot change `mcp_enabled` or `mcp_port`. The server has no way to switch itself off or move itself, which would disconnect the client that asked. Both are changed on the [Settings](settings.md) screen.

### Changing a Project's launch commands

A Project may override any of the three launch commands, and `update-project-launch-commands` writes those overrides. It identifies the Project by either `path` or `uuid`, the same way `add-workspace` does, and is the MCP equivalent of the `Edit launch commands` button on the Project screen.

The three values behave as they do for `update-settings`: omitted or `null` keeps what is stored, a string sets it, and an empty string clears the override so the Project falls back to the global command again. A cleared override is stored as nothing at all rather than as a blank command, which would otherwise leave that Project launching nothing.

Which command actually runs is described in [Projects and Workspaces](projects-and-workspaces.md): the Project's override when it has one, the global command otherwise.

## Errors

A tool that fails returns the underlying message as an MCP error rather than raising anything, so the failure arrives as readable text in the agent's transcript: `Workspace at path '/tmp/nope' not found.`, `Project with UUID '…' not found.`, `The projects file [.laborforest/projects.yaml] is invalid: …`. An agent can usually act on these without your help.

Failures to connect at all are a different matter, because most clients report a missing server, a wrong port and a refused request identically. The `Test connection` button on the [Settings](settings.md) screen tells the cases apart.

## Constraints

The server binds to `127.0.0.1` and cannot be moved to another interface. There is no configuration for exposing it on a network, and the endpoint is plain HTTP, which is correct for a loopback address.

There is no authentication, no rate limiting and no per-tool gating. Every tool is exposed whenever the server is running; there is no way to publish a read-only subset.

The server runs only while the app does. Disabling MCP stops the process outright, and a client then finds nothing listening rather than a server that answers with fewer tools. Changing the port stops the process and starts a new one on the new port.

The port has to be between `1024` and `49151`, and it cannot be the port the app window is served on.

The server exposes no prompts and no interactive MCP apps, only the tools and resources above. It reports the app's own version to clients, and is not versioned separately.

Everything a tool does runs as the logged-in user, on the same machine, with that user's git credentials and shell.

---

| Previous                  | Next |
|---------------------------|------|
| [CLI tools](cli-tools.md) |      |
