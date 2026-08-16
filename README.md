# 💪🌲 LaborForest
A desktop app for macOS to manage git worktrees and local action workflows.

![LaborForest Demo](docs/images/demo.gif)

## Documentation
Please read the documentation below:
- [Introduction](docs/introduction.md)
- [Dashboard](docs/dashboard.md)
- [Settings](docs/settings.md)
- [Projects & Workspaces](docs/projects-and-workspaces.md)
- [Workflows](docs/workflows.md)
- [Example with Laravel](docs/example-with-laravel.md)
- [CLI tools](docs/cli-tools.md)

If you have questions, you can [open an issue](https://github.com/bricehartmann/labor-forest/issues/new).

Pull requests are welcome!

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
