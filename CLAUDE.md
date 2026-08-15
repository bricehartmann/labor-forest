# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
composer setup        # first-time setup: install, .env, key:generate, migrate, npm install + build
composer native:dev   # run the desktop app (NativePHP window + Vite HMR) — the normal way to develop
composer dev          # web-only dev: artisan serve + queue:listen + pail + vite
composer test         # config:clear + full test suite
php artisan test --compact --filter=testName   # single test
vendor/bin/pint --dirty --format agent         # format changed PHP files
php artisan native:build                       # package the desktop app (output: nativephp/electron/dist)
composer logs:tail    # tail packaged-app log (~/Library/Application Support/laborforest-dev/storage/logs/laravel.log)
composer logs:wipe    # truncate that log
```

`native:build` prebuild chain (see `config/nativephp.php`): `npm run build` → `php artisan app:patch-mac-entitlements` → `php artisan optimize`.

## Architecture

LaborForest is a **NativePHP v2 (Electron) macOS desktop app** — a GUI for managing git worktrees ("workspaces") of local repositories ("projects") and running user-authored YAML workflows inside them. The UI is a single Filament v5 panel (`app/Providers/Filament/AppPanelProvider.php`, path `''`). There are no web routes and no Filament Resources — only Pages (`Project`, `ProjectWorkflows`, `WorkflowLog`, `Settings` in `app/Filament/Pages/`) and Widgets. Project nav items are generated dynamically from projects.yaml at panel boot.

### State lives in YAML files, not the database

- Global: `~/.laborforest/settings.yaml` and `~/.laborforest/projects.yaml`, accessed via the `user_home` disk (`app/Enums/Disk.php`). That disk is registered at runtime by NativePHP — it does not exist outside the native runtime and is not in `config/filesystems.php`.
- Per-workspace: a `.laborforest/` directory inside each worktree holding `workflows/*.yaml`, `logs/*.yaml`, and `status.yaml` (paths defined by enums `Directory` and `File`).
- SQLite is used **only as the queue backend** — the sole domain migration is the jobs table. `app/Models/User.php` is an unused stub.
- Every domain object is a `spatie/laravel-data` class in `app/Data/` with `rules()` that validate user-authored YAML (`ProjectData`, `WorkspaceData`, `WorkflowData`, `WorkflowRunLogData`, …). Some override `transform()` to keep generated YAML clean.
- Shared file helpers: `app/Concerns/Services/ManagesFiles.php`.

### Workflow execution flow

Filament page action → `WorkflowService::dispatchWorkflow()` → `RunWorkflow` job on the database queue → sets workspace status to WORKING, writes a run log with a pending entry per step, then iterates steps. Step types (`app/Enums/WorkflowStepType.php`):

- `shell` — Symfony Process in the workspace cwd, wrapped in `set -eu; set -o pipefail` so mid-chain failures surface
- `update_env` — rewrites keys in the workspace's `.env`
- `workflow` — runs a child workflow inline (parent fails with it; cycles guarded via `ancestorWorkflowNames`)

Steps support `if`/`unless` shell gates and per-step `env`. Progress is pushed to the UI by broadcasting on the NativePHP channel (`app/Enums/BroadcastChannel.php`): `WorkflowStarted`, `WorkflowStepStarted/Finished/Skipped`, `WorkflowStepOutputUpdated` (throttled to 1/sec with a final flush), `WorkflowFinished`. Final status = `ERROR` on failure, else the workflow's `ending_status`.

### Services (`app/Services/`)

- `GitService` — worktree/branch/status operations via the git CLI
- `ProjectsService` — projects.yaml CRUD, workspace lifecycle, seeds starter up/down workflows, initializes `.laborforest/`
- `WorkflowService` — loads/validates workflow YAML, builds run logs, dispatches jobs
- `VariableReplacementService` — resolves `{{ }}` placeholders (`app/Enums/Variable.php`), including dynamic `ENV_*` read from the workspace's own `.env`
- `ProcessEnvironmentService::sanitized()` — strips LaborForest's own env from spawned processes (`app/Enums/HostEnvKey.php`) so workspace commands don't inherit this app's `.env`
- `LaunchService` — opens the configured IDE/browser/terminal for a workspace
- `SettingsService` — settings.yaml load/save/sync

Each service throws typed exceptions from `app/Exceptions/` (`GitStatusNotClean`, `InvalidWorkflowFile`, `UnresolvedVariable`, …). Magic strings live in backed enums in `app/Enums/`.

### Gotchas

- `AppServiceProvider::hardenNativeDatabaseConnection()` re-applies WAL/busy_timeout/IMMEDIATE to the `nativephp` sqlite connection because NativePHP rewrites it at boot; removing this brings back non-retryable "database is locked" errors in queue pop.
- `vendor/nativephp/desktop/resources/build/app/` contains a stale copy of this entire application — vendor grep hits may be misleading duplicates of `app/` files.
- laravel-data structure caching is disabled in `config/data.php`. `php artisan optimize` (native:build prebuild) runs `data:cache-structures`, and the native runtime rebinds `storage_path()` to `~/Library/Application Support/laborforest-dev/storage` — terminal `optimize:clear` writes to the project storage and never reaches that copy, so a cached structure silently drops properties added to `app/Data/` classes.
- Frontend is minimal: `resources/js/app.js` is empty; all UI is server-rendered Filament/Livewire. Tailwind v4 is CSS-first (no tailwind.config.js) — theme config in `resources/css/app.css` and `resources/css/filament/app/theme.css`, which safelists dynamically-built status color classes via `@source inline(...)`.
- Livewire components `WorkflowLogStep` (ANSI→HTML step output) and `WorkflowNotifications` (listens for `native:App\Events\WorkflowFinished`) are injected globally via Filament render hooks in `AppServiceProvider`.
- Tests: Pest 5 with sqlite `:memory:` and sync queue; currently only stub ExampleTests exist.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- filament/filament (FILAMENT) - v5
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- livewire/livewire (LIVEWIRE) - v4
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v5
- phpunit/phpunit (PHPUNIT) - v13
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Follow existing application Enum naming conventions.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>
