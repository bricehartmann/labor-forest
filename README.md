# 💪🌲 LaborForest
A desktop app for macOS to manage git worktrees and local action workflows.

![LaborForest Demo](docs/images/demo.gif)

## Article
You can read the article introducing this project [here](https://brice.codes/posts/parallel-claude-code-sessions-with-laborforest).

## Documentation
Please read the documentation below:
- [Introduction](docs/introduction.md)
- [Dashboard](docs/dashboard.md)
- [Settings](docs/settings.md)
- [Projects & Workspaces](docs/projects-and-workspaces.md)
- [Workflows](docs/workflows.md)
- [Example with Laravel](docs/example-with-laravel.md)
- [CLI tools](docs/cli-tools.md)
- [MCP](docs/mcp.md)

If you have questions, you can [open an issue](https://github.com/bricehartmann/labor-forest/issues/new).

Pull requests are welcome! Contributions are accepted under the GPL-3.0-or-later license.

## Feature overview
- Manage "Projects" (local repositories)
    - choose to commit or ignore the configuration directory
- Manage "Workspaces" (a specific branch in a worktree)
    - create Workspaces for new or existing branches
    - track a Workspace's status, showing whether a Workspace is `ready` for work or `suspended` (dev environment not running)
    - quickly launch your configured Terminal, IDE, or Browser for a Workspace
    - easily remove a Workspace and clean up the worktree and branch
- Run local Workflows to spin up, tear down, or modify local development environments, per Workspace
    - write Workflows in a style inspired by GitHub Actions (that run locally)
    - use template variables specific to the Project, Workspace, or a value in your `.env` file
    - conditionally run or skip steps based on the outcome of a shell command
    - run nested Workflows
    - validate or launch Workflows from a terminal
- Review Workflow output logs in the application
    - watch output appear in realtime
    - review historical logs
    - diagnose Workflow failures
    - bulk delete old log files

### Local MCP server

- Use your favorite agentic coding tool to control LaborForest
- Configure the local MCP server as read-only or writeable
- Manage Projects and Workflows
- Write, run, validate, and diagnose Workflows
- Update configured settings

### Example workflows included

- Kickstart your project with example Workflows
    - Laravel (Herd, MinIO, Redis)
    - JavaScript
    - Bare (starting point)

### System requirements:
- macOS (arm64 or x64)

## Tech stack
- NativePHP + Laravel
- Livewire
- Filament
- TailwindCSS

## Building from source
Clone the repo and run the command below:

```shell
composer run setup
```

## Building for development
Application hot reloads upon changes.
```shell
composer run native:dev
```

## Building for production
Output: `nativephp/electron/dist/LaborForest-1.0.0-arm64.dmg`
```shell
composer native:build mac arm64
```

## Important directories
- `~/.laborforest` - tracks projects and settings
- `<project>/.laborforest/ignored` - tracks workspace status, holds workflow run logs
- `<project>/.laborforest/workflows` - holds workflows for the project

## License
Copyright (C) 2026 Brice Hartmann

LaborForest is free software: you can redistribute it and/or modify it under the
terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE. See the [GNU General Public License](LICENSE.md) for more details.
