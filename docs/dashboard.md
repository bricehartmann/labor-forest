# Dashboard

## Overview

The Dashboard is the landing screen when you first open LaborForest. You can navigate back to it at any time using the menu on the left.

![LaborForest - Dashboard](images/dashboard.png)

## Current application version

The current application version installed to your device is shown in widget on the dashboard. If your application version is up to date, a green badge will show denoting such. If your application version is behind, an orange button will show which links to the latest release page on GitHub. To upgrade, you must manually uninstall your current version, download the latest version, and reinstall (no automatic updates).

LaborForest checks GitHub for the latest release at most once every 15 minutes and remembers the answer in between, so a release published moments ago may take that long to appear.

## Reading the documentation

Clicking `Read the Docs` opens the LaborForest documentation on GitHub in your default web browser.

An installed release opens the documentation as it stood at the version shown in the `App Version` widget, so it always describes the build you are running. Running LaborForest from source opens the documentation on the `main` branch instead.

## Adding a project

Click the green `Add project` button and select the root of your project directory. The directory must already be a git repository, it must not already be registered with LaborForest, and its git status must be clean. Adding a project with uncommitted changes fails with an error notification.

Adding a project creates a `.laborforest` directory at the root of the repository, which leaves the repository dirty. LaborForest prompts you to commit that directory, exclude it locally, or leave it alone. See [Projects & Workspaces](projects-and-workspaces.md) for what each option does.

Once the project is added, LaborForest opens it automatically.

## Installing CLI tools

LaborForest ships with CLI tools that let you add a `Project` or run a `Workflow` from the command line. You must install them into a directory on your system `PATH` before you can use them. Read more about the CLI tools [here](cli-tools.md).

The `Install CLI tools` panel also has a `Dismiss` button. Dismissing hides the panel permanently, and there is no way to bring it back from the UI. To show it again, set `cli_tools_dismissed` to `false` in `~/.laborforest/settings.yaml`.


---

| Previous                        | Next                    |
|---------------------------------|-------------------------|
| [Introduction](introduction.md) | [Settings](settings.md) |
