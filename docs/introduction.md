# Introduction

## What LaborForest is

LaborForest is a macOS desktop app for managing the git worktrees of your local repositories and running local workflows inside them. It exists to set up, tear down, and otherwise methodically modify local development environments. It is built with NativePHP, Laravel, Livewire, Filament, and TailwindCSS.

## What LaborForest is not

LaborForest is not a replacement for cloud-based CI/CD. Everything runs on your machine, against your working copies. It is also not a way to interact with or manage LLM agents.

## Git worktrees

Git worktrees let you check out multiple branches of the same repository in separate directories at the same time. Worktrees and branches are independent of each other, so any branch can be checked out in any worktree.

## Pain points

Each new worktree usually needs setup and teardown work before it is usable. That work typically involves an environment file, a database, mocked cloud storage, a local web server, and whatever else the project depends on. Doing it by hand for every worktree is repetitive and easy to get wrong.

## The LaborForest approach

LaborForest couples a branch to a worktree and calls that combination a `Workspace`. It then runs a sequence of steps against a `Workspace` to set it up, tear it down, or modify it. LaborForest calls a collection of these steps a `Workflow`.

A `Workflow` is a YAML file you write yourself, stored inside the workspace it runs against. Steps run sequentially on your local machine, in the workspace directory, and the app reports each step's output as it runs.
