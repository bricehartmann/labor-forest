# CLI tools

## Overview

NativePHP, via Electron, does not provide a way to specify command line arguments when launching an application. A workaround is a small bash script that writes your request to a file and then wakes the application using NativePHP's deep linking. The application reads the request and acts on it.

## Installing the CLI tools

Use the button on the [Dashboard](dashboard.md) to install the CLI tools, then choose the directory to install into. The dialog defaults to `/usr/local/bin` when that directory exists, but you can choose any directory. Pick one that is on your system `PATH`.

The installation creates a symlink named `lf` pointing at the script inside the LaborForest application bundle. Moving, renaming, or deleting LaborForest breaks the installed `lf`.

LaborForest first tries to create the symlink directly. If that is denied, it retries with administrator privileges, which is when macOS prompts you for your password. Cancelling that prompt fails the installation.

There is no uninstall command. To remove the CLI tools, delete the symlink yourself.

## Using the CLI tools
![LaborForest - CLI Tools Help](images/cli-tools-help.png)

Run `lf add-project` to add the current working directory as a Project in LaborForest. The directory must be a git repository with a clean git status, and it must not already be registered, the same requirements the `Add project` button enforces.

Run `lf run <workflow>` to trigger the run of a Workflow in LaborForest using the current Workspace directory. The current directory must be the root of a Workspace, and that Workspace must belong to a registered Project. Running from a subdirectory does not work. The script checks that `.laborforest/workflows/<workflow>.yaml` exists before waking the app, and exits with an error if it does not. A run started this way runs every step of the Workflow, and it is subject to the same status rules as a run started from the UI.

Run `lf validate <workflow>` to check a Workflow file for problems without running it. The requirements are the same as `lf run`: the current directory must be the root of a Workspace belonging to a registered Project, and the script checks that `.laborforest/workflows/<workflow>.yaml` exists before waking the app. The application parses and validates the file, then opens on the Workflow's Project page. A valid Workflow shows a green notification. An invalid one shows a red notification, listing the file path and every problem found. Both notifications stay on screen until you dismiss them. Nothing is queued, no run log is written, and the Workspace status is unchanged.

Run `lf --help` to display the help message. Running `lf` with no arguments prints the same message. An unrecognized command prints an error and exits with a non-zero status.

When a command fails inside the application, the app opens on a page carrying the error as a red notification, because the command runs outside the application window's session. A `lf validate` failure that is the Workflow's own invalidity reports on that Workflow's Project page; every other failure reports on the Dashboard.

### Cold starts

LaborForest does not need to be running in order to run the above commands. If the application is not already running, it is started before your command is processed.

---

| Previous                                        | Next |
|-------------------------------------------------|------|
| [Example with Laravel](example-with-laravel.md) |      |
