# Projects & Workspaces

## Overview

Every `Project` LaborForest is aware of appears in the menu on the left, sorted by last opened, descending. Opening a project updates its last opened timestamp, so it moves to the top of the list on the next page load.

A `Project` is a local git repository. A `Workspace` is one git worktree of that repository together with the branch checked out in it. The repository directory you originally added is the primary Workspace, and every worktree you add afterwards is a linked Workspace.

![LaborForest - Projects](images/project.png)

## New Projects

When you first add a `Project`, it is opened in LaborForest automatically. Adding a project creates a `.laborforest` directory at the root of your project directory, which leaves your git status dirty. The directory initially holds an `ignored` subdirectory, which contains the Workspace's status file and, later, workflow run logs. The `ignored` subdirectory ignores itself in git. The `workflows` directory is created the first time workflows are added to the Workspace.

Because the new directory makes the repository dirty, LaborForest prompts you with three options. `Commit all changes` stages every change in the repository and commits it with the message you provide. `Add to .git/info/exclude` writes `/.laborforest/` to the repository's local exclude file, which is not shared with anyone else. `Do nothing` leaves the git status dirty.

If you are working on a team where multiple people use LaborForest, commit the directory. If you do not want LaborForest to leave any trace in your repository, add the directory to `.git/info/exclude`.

![LaborForest - Projects](images/new-project.png)

## Adding a workspace

Add a `Workspace` to the open `Project` using the green button in the upper right. You choose between creating a new branch and using an existing branch. Selecting an existing branch lists only branches that do not already have a Workspace. Creating a new branch asks for the branch name and the base branch to create it from, defaulting to the branch currently checked out in the project's primary directory.

The new worktree is created next to your project directory, named after the project directory and the branch, so a project at `~/code/acme` with a branch named `feature/login` produces `~/code/acme-feature-login`. Adding the Workspace fails if that directory already exists.

A new Workspace starts with the status `suspended`, meaning nothing has been set up in it yet.

![LaborForest - Add Workspace](images/add-workspace.png)

## The workspace table

Each Workspace occupies one row. The `Primary` column marks the original project directory, `Branch` shows the checked-out branch, `Status` shows the Workspace status described below, and `Git` shows whether that worktree's git status is `clean`, `dirty`, or `unknown`.

The table updates itself while a workflow runs, so statuses change without you refreshing the page.

## Workspace statuses

| Status      | Meaning                                                                     |
|-------------|-----------------------------------------------------------------------------|
| `pending`   | A workflow run has been dispatched but has not started yet                    |
| `working`   | A workflow is currently running against the Workspace                         |
| `ready`     | The Workspace is set up and usable                                            |
| `suspended` | The Workspace has been torn down, or has not been set up yet                  |
| `error`     | The last workflow run failed                                                  |
| `unknown`   | LaborForest could not read the Workspace's status file                        |

Workflows can only be run against a Workspace that is `ready` or `suspended`. A Workspace in `pending`, `working`, `error`, or `unknown` runs nothing until its status changes. For `error` and `unknown` you change it yourself with the `Override status` action described below.

## Launch menu button

The `Launch` table row action button opens a menu where you choose which launch command to run. Click `Terminal` to run the configured command that opens your terminal with a working directory of your Workspace. Click `IDE` to run the configured command that opens your Workspace in your IDE of choice. Click `Browser` to run the configured command that opens the Workspace's local site in a web browser.

An entry only appears when a command is configured for it, either globally in [Settings](settings.md) or as a project-level override. If neither is set, that entry is hidden.

![LaborForest - Launch Menu](images/launch-menu.png)

## Editing launch commands

Launch commands can be overridden at the `Project` level using the purple button in the upper left. Leave a command blank to use the global default for that command. Each field's placeholder shows the global value it falls back to, and the modal includes a reference table of the available variables.

![LaborForest - Edit Launch Commands](images/edit-launch-commands.png)

## Workflows menu button

The `Workflows` table row action button opens a menu listing every `Workflow` that exists across the Project's Workspaces. Entries that cannot be run for a given Workspace are shown disabled rather than hidden, either because the Workflow does not exist in that Workspace or because the Workspace's current status does not satisfy the Workflow's `require_status`.

Selecting a Workflow opens a modal listing its steps, each with a checkbox that is checked by default. Uncheck a step to skip it. `Run` starts the run and keeps you on the Project screen, and `Run & watch` starts the run and takes you straight to its live log.

If no Workflows exist yet, the menu offers `Create example workflows`, which lets you choose between the `Bare`, `JavaScript`, and `Laravel` example sets. This entry disappears once any Workflow exists in the Project.

**Important**: Workflows are copied from the base branch at the time of Workspace creation. It is recommended to establish your Workflows in your `main` branch before continuing with additional Workspaces.

**Important**: If you do not commit your `.laborforest` directory, a Workflow you create in a linked Workspace exists only in that worktree's directory. Removing the Workspace deletes the directory and the Workflow with it, and it is not recoverable from git. Git also refuses to remove a worktree that holds untracked files, so such a removal requires the `Force worktree removal` option, which discards them without a further warning. To keep a Workflow you wrote in a linked Workspace, copy the file into the primary Workspace's `.laborforest/workflows` directory before removing the Workspace, so that later Workspaces are seeded from it.

The copy reads the `.laborforest/workflows` directory of the Workspace the base branch is checked out in, which is how Workflows kept out of git reach a new Workspace at all. A Workspace created from an existing branch names no base branch, so the branch checked out in the primary Workspace stands in for one. If the base branch has no Workspace, the primary Workspace is used. If you commit your Workflows, they arrive with the checkout and no copy is made. Nothing is copied after creation, so a Workspace that starts with no Workflows keeps none until you add them.

## Logs menu button

The `Logs` table row action button takes you to a table of logs, one for each previous or ongoing Workflow run for that Workspace.

## Extra actions menu button

The extra row actions button (kebab menu) lets you override a Workspace's status or remove the Workspace entirely.

Overriding a Workspace's status is useful when a Workflow run resulted in an error and manual clean up was required to get the Workspace back into a stable state. The only statuses you can select are `ready` and `suspended`.

![LaborForest - Extra Actions Menu](images/extra-actions-menu.png)

Removing a Workspace is not offered for the primary Workspace, and it is not offered while the Workspace's status is `ready`, `working`, or `pending`. That leaves `suspended`, `error`, and `unknown` as the statuses a linked Workspace can be removed from. To get rid of the primary Workspace, remove the Project.

When removing a Workspace you can force removal of the worktree, delete the branch, and force delete the branch. Force deleting the branch is only available once you have chosen to delete the branch.

![LaborForest - Remove Workspace](images/remove-workspace.png)

## Removing a project

Remove a Project using the red button near the top. The project directory itself is never deleted.

You can choose to remove the Project's existing worktrees, which excludes the primary one and leaves their branches in place. This option only appears when linked Workspaces exist, and removal is always forced when you select it. You can also choose to remove the `.laborforest` directory, which removes it from the primary Workspace only and may leave a dirty git status there. Any linked worktree left behind keeps its own `.laborforest` directory.

Removing a Project does not remove the `/.laborforest/` entry from `.git/info/exclude` if you added one. Re-adding the same project later keeps that local exclusion.

![LaborForest - Remove Project](images/remove-project.png)

---

| Previous                | Next                      |
|-------------------------|---------------------------|
| [Settings](settings.md) | [Workflows](workflows.md) |
