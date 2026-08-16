# Introduction

## What LaborForest is
- A desktop app for macOS to manage git worktrees and local action workflows.
- A way to set up, tear down, or otherwise methodically modify local development environments.
- Built using NativePHP, Laravel, Livewire, Filament, and TailwindCSS.

## What LaborForest is not
- A replacement for cloud-based CI/CD.
- A way to interact with or manage LLM agents.

## Git worktrees
- Git worktrees allow you to check out multiple branches of the same repository in separate directories, at the same time
- Git worktrees are independent of branches (any branch can be checked out in any worktree)

## Pain points
- For each new git worktree, setup and/or tear down steps for the local development environment are often required
- e.g. environment file, databases, mocked cloud storage, local web server, etc

## The LaborForest approach
- LaborForest approaches this by:
  - coupling branches to worktrees (LaborForest calls the combination a `Workspace`)
  - allowing local action runners to methodically run a sequence of steps to set up, tear down, or modify a `Workspace`
    - LaborForest calls a collection of these steps a `Workflow`
