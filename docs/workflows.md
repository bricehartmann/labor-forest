# Workflows

## Overview

Workflows are sets of sequential steps defined in `.yaml` files. They run on your local machine, in the directory of the Workspace they were started from. They are very loosely inspired by GitHub Actions.

Each Workspace has its own workflows, stored in that worktree's `.laborforest/workflows` directory. A new Workspace is seeded from the base branch it was created from, as described in [Projects & Workspaces](projects-and-workspaces.md).

Example Workflows are included and can be added from the `Workflows` row action menu. The sets are `Bare`, `Laravel`, and `JavaScript`.

## Reviewing workflow logs

The `Logs` table row action button links to a screen that lists all previous and current Workflow runs for a Workspace, newest first. Each row shows the run's timestamp, the Workflow name, its status, and, for a run started by a nested workflow step, the parent Workflow that started it.

Workflow run logs can be removed by selecting the row for the Workflow run(s) and clicking the red `Delete` button above. Runs that are still pending or running cannot be selected, so they cannot be deleted until they finish.

![LaborForest - Workflow Run Logs](images/workflow-run-logs.png)

Viewing a specific Workflow run displays information and console output for each step. Standard output and standard error are interleaved into a single stream in the order they arrive.

![LaborForest - Workflow Run In Progress](images/workflow-run-in-progress.png)

The displayed information updates in realtime without needing to refresh.

![LaborForest - Workflow Run Success](images/workflow-run-success.png)

Run logs are stored as YAML files in the Workspace's `.laborforest/ignored/logs` directory, one file per run. That directory is ignored by git.

### Skipped steps

A step can be skipped for four reasons. It was not selected in the run modal, its `if` condition failed, its `unless` condition matched, or it was aborted because an earlier step failed.

## Implementing workflows

Workflows are managed manually through your YAML editor of choice. A Workflow's name is its file name without the extension, so `up.yaml` is run as `up`. A `name` key inside the file is ignored.

Files must end in `.yaml`. A file ending in `.yml` is ignored entirely.

File names should be `kebab-case`. This is a convention rather than a rule, but two things depend on it. The label shown in the `Workflows` menu is derived from the file name, so `db-refresh` is displayed as `Db Refresh`. Run log identifiers slugify the name, so `db-refresh.yaml` and `db_refresh.yaml` produce colliding log identifiers.

The structure of a workflow is defined below:

```yaml
resource_type: workflow
require_status: <ready|suspended>
ending_status: <ready|suspended>
sort_order: <int>
steps:
  - name: 'Step 1'
    type: shell
    run: 'echo "Step 1"'
  - name: 'Step 2'
    type: shell
    run: 'echo "Step 2"'
```

`resource_type` must be `workflow` for the file to be recognized. `sort_order` and `steps` are required. `require_status` and `ending_status` are optional.

A Workflow with an empty `steps` list never appears in the menu. A file that declares `resource_type: workflow` but fails validation stops the entire `Workflows` menu from loading for that Workspace, so a single malformed file hides every Workflow until you fix it.

### Required and ending statuses

To require that a Workspace is within a specific status in order to be run, use `require_status`. To update the Workspace status after a successful run, use `ending_status`. Both accept only `ready` or `suspended`; any other value is rejected.

Whether a Workflow can run depends on the Workspace's current status and the Workflow's `require_status`:

| Current status | No `require_status` | `require_status: ready` | `require_status: suspended` |
|----------------|---------------------|-------------------------|-----------------------------|
| `ready`        | Allowed             | Allowed                 | Blocked                     |
| `suspended`    | Allowed             | Blocked                 | Allowed                     |
| `pending`      | Blocked             | Blocked                 | Blocked                     |
| `working`      | Blocked             | Blocked                 | Blocked                     |
| `error`        | Blocked             | Blocked                 | Blocked                     |
| `unknown`      | Blocked             | Blocked                 | Blocked                     |

A Workspace in `error` or `unknown` can run nothing at all. Use the `Override status` action described in [Projects & Workspaces](projects-and-workspaces.md) to set it back to `ready` or `suspended`.

While a Workflow runs, the Workspace status is `working`. If any step fails, the final status is `error`. Otherwise the Workflow's `ending_status` is applied. A Workflow that declares no `ending_status` returns the Workspace to the status it held before the run started.

This gate is enforced when the run is dispatched, not only in the UI, so a run started from the CLI is subject to the same rules.

### Sort order

The `sort_order` attribute controls the order the Workflow appears in the `Workflows` table row action button menu. Workflows sharing a `sort_order` are ordered alphabetically by name. Negative values are allowed.

### Variables

Workflow steps support the same `{{ VARIABLE }}` tags as launch commands, listed in [Settings](settings.md). They are replaced in a step's `run`, `if`, and `unless` commands, in the values of `env` and `map`, and in the workflow name of a nested `workflow` step. They are not replaced in `env` or `map` keys, nor in a step's `name`.

Variables prefixed with `ENV_` read from the Workspace's `.env` file, and the file is re-read for each step. A step that changes the `.env` file therefore affects the values seen by every step after it.

An unrecognized variable makes the whole Workflow file invalid. A well-formed `ENV_` variable whose key is missing from the `.env` file fails the run at the point the step executes.

### Step environment

LaborForest strips its own environment from the processes it spawns, so a workflow step does not inherit LaborForest's configuration. Variables such as `PATH`, `HOME`, `USER`, `SHELL`, and `LANG` are preserved. If your step needs a variable whose name also exists in LaborForest's own environment, set it explicitly with `env` or read it with `{{ ENV_* }}`.

### Types of Steps

There are three types of steps. `shell` runs a shell command, `update_env` updates values in the Workspace's `.env` file, and `workflow` runs a nested Workflow.

Every step type supports `if` and `unless`. Both are shell commands, evaluated in the Workspace directory. `if` skips the step when its command exits non-zero. `unless` skips the step when its command exits zero. A condition's own failure never fails the Workflow.

#### Step type: `shell`
The `shell` step type runs a shell command.
```yaml
name: <name>
type: shell
run: <command>
if: <condition>
unless: <condition>
env:
  KEY_ONE: 'value1'
  KEY_TWO: 'value2'
```
Only the `name`, `type`, and `run` attributes are required. `if` or `unless` can be used to conditionally run the step. `env` can be used for environment variables available to the `run`, `if`, and `unless` commands.

The command runs in the Workspace directory. It is wrapped in `set -eu; set -o pipefail`, so an unset variable, a failing command in a chain, or a failing command in a pipeline fails the step. Conditions are deliberately not wrapped, because their raw exit code is the signal.

Each command the step spawns is subject to the workflow timeout configured in [Settings](settings.md).

#### Step type: `update_env`
The `update_env` step type updates the `.env` file in the Workspace directory. Any keys unspecified are left untouched.
```yaml
name: <name>
type: update_env
if: <condition>
unless: <condition>
map:
  KEY_ONE: 'value1'
  KEY_TWO: 'value2'
```
Only the `name`, `type`, and `map` attributes are required. `if` or `unless` can be used to conditionally run the step. `map` is a map of key/value pairs to update.

The `.env` file is created if it does not exist. For each key, the first matching line is replaced, and the key is appended if it is not present. Comments, blank lines, and key order are preserved. Values containing whitespace, quotes, `#`, or `=` are written double quoted, and other values are written as-is. An `update_env` step cannot fail on its own; it only fails the Workflow if a variable in it cannot be resolved.

#### Step type: `workflow`
The `workflow` step type runs a nested workflow.
```yaml
name: <name>
type: workflow
run: <workflow>
```

`run` is the name of another Workflow in the same Workspace. `if` and `unless` are supported here as well.

Nested workflows do not evaluate the `require_status` attribute. If a nested Workflow and the parent Workflow differ in `ending_status`, the parent's `ending_status` wins.

A nested Workflow runs inline as part of the parent run, and it always runs all of its own steps regardless of which steps you selected for the parent. If it fails, the parent stops at that step and fails too. The step links to the nested run's own log.

A Workflow cannot appear twice in the same chain. If it does, the step fails immediately and reports the chain that caused it.

---

| Previous                                             | Next                                             |
|------------------------------------------------------|--------------------------------------------------|
| [Projects & Workspaces](projects-and-workspaces.md)  | [Example with Laravel](example-with-laravel.md)  |
