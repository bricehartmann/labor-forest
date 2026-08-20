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
composer native:build # package the desktop app; entitlements auto-selected (output: nativephp/electron/dist)
composer logs:tail    # tail packaged-app log (~/Library/Application Support/laborforest-dev/storage/logs/laravel.log)
composer logs:wipe    # truncate that log
```

`native:build` prebuild chain (see `config/nativephp.php`): `npm run build` → `php artisan optimize`.

macOS entitlements are patched by the composer script, not by prebuild, because a failing prebuild command only logs and lets the build continue (`HasPreAndPostProcessing::runProcess()`), while composer aborts the chain. `app:patch-mac-entitlements` overwrites NativePHP's `build/entitlements.mac.plist` — which resolves to the *vendor* copy, `vendor/nativephp/desktop/resources/electron/build/` — with one of two committed plists:

- `resources/nativephp/entitlements.mac.plist` adds `com.apple.security.cs.disable-library-validation`, required because an ad-hoc signed build has no Team ID.
- `resources/nativephp/entitlements.mac.default.plist` is a verbatim copy of NativePHP's own plist. The patch is sticky, so a release build has to write the defaults back rather than merely skip the patch — hence a second file rather than a conditional.

Which one is used is inferred from `config('nativephp-internal.notarization')`: all three of `NATIVEPHP_APPLE_ID`, `NATIVEPHP_APPLE_ID_PASS` and `NATIVEPHP_APPLE_TEAM_ID` `filled()` means a Developer ID release and selects the defaults, anything less selects the ad-hoc overrides. Read through config rather than `env()`, which returns null under a cached config, and `filled()` rather than a null check, because a half-filled `.env` leaves the others as empty strings. `--adhoc` and `--default` force a profile when the credentials don't reflect the intent (combining them is an error). The credentials only prove notarization is configured, not that a signing certificate exists, so the command prints which profile it picked and why. Running `php artisan native:build` directly patches nothing and inherits whatever the vendor plist currently holds.

CI (`.github/workflows/test.yaml`) runs `composer test` and `vendor/bin/pint --test` on push/PR to `main` (PHP 8.4, Node 22).

## Architecture

LaborForest is a **NativePHP v2 (Electron) macOS desktop app** — a GUI for managing git worktrees ("workspaces") of local repositories ("projects") and running user-authored YAML workflows inside them. The UI is a single Filament v5 panel (`app/Providers/Filament/AppPanelProvider.php`, path `''`). There are no web routes and no Filament Resources — only Pages (`Dashboard`, `Project`, `ProjectWorkflows`, `WorkflowLog`, `Settings` in `app/Filament/Pages/`) and Widgets. Project nav items are generated dynamically from projects.yaml at panel boot.

### State lives in YAML files, not the database

- Global: `~/.laborforest/settings.yaml`, `~/.laborforest/projects.yaml`, and the transient `pending.yaml`, accessed via the `user_home` disk. Read-only app assets (`extras/bin/lf`, `extras/example-workflows/`) come from the `extras` disk. Both cases live in `app/Enums/Disk.php`; **both disks are registered at runtime by NativePHP** — they do not exist outside the native runtime and are not in `config/filesystems.php`.
- Per-workspace: a `.laborforest/` directory inside each worktree holding `workflows/*.yaml` (a hand-authored `.yml` is read too — `Concerns\Services\ResolvesWorkflowFiles` resolves a workflow name to whichever spelling exists, preferring `.yaml`, and `loadWorkflows()` drops a `.yml` whose `.yaml` sibling exists because both key on the same name) plus an `ignored/` subdirectory (git-ignored) holding `logs/*.yaml` and `status.yaml` (paths defined by enums `Directory` and `File`). A project may instead keep the whole `.laborforest` dir out of git via `.git/info/exclude` (`Directory::GIT_INFO` / `File::GIT_EXCLUDE`), in which case workflows are copied into a new worktree at creation time from the worktree the base branch is checked out in (`ProjectsService::seedWorkspaceWorkflowsFromBaseBranch()`, falling back to the primary dir). Nothing re-seeds on page load.
- Workflow and run-log YAML files are recognized by their `resource_type` key (`app/Enums/YamlResourceType.php`), not by filename or location.
- SQLite is used **only as the queue backend** — the sole domain migration is the jobs table. `app/Models/User.php` is an unused stub.
- Every domain object is a `spatie/laravel-data` class in `app/Data/` with `rules()` that validate user-authored YAML (`ProjectData`, `WorkspaceData`, `WorkflowData`, `WorkflowRunLogData`, …). Some override `transform()` to keep generated YAML clean.
- Shared file helpers: `app/Concerns/Services/ManagesFiles.php`.

### Workflow execution flow

Filament page action (or the `lf` CLI, below) → `WorkflowService::dispatchWorkflow()` → `RunWorkflow` job on the database queue → sets workspace status to WORKING, writes a run log with a pending entry per step, then iterates steps. Step types (`app/Enums/WorkflowStepType.php`):

- `shell` — Laravel's Process facade in the workspace cwd, wrapped in `set -eu; set -o pipefail` so mid-chain failures surface
- `update_env` — rewrites keys in the workspace's `.env`
- `workflow` — runs a child workflow inline (parent fails with it; cycles guarded via `ancestorWorkflowNames`)

A workflow file declares `require_status`, `ending_status`, `sort_order` and its `steps` (`app/Data/WorkflowData.php`). Whether it may run is decided in one place — `WorkspaceStatus::allowsWorkflowRequiring()` — and enforced in `WorkflowService::ensureWorkspaceCanRunWorkflow()` (throws `WorkflowNotRunnable`) rather than only by disabling the UI button, so a CLI-dispatched run cannot bypass it. Child `workflow` steps are deliberately exempt from the gate.

Steps support `if`/`unless` shell gates and per-step `env`. A gate's exit code is only ever an answer — non-zero `if` or zero `unless` skips the step, never fails the run — but a gate that never reaches an exit code (it times out, or a `{{ }}` in it will not resolve) fails the step it guards. That, and a `shell` step killed by the timeout, is recorded as `WorkflowRunLogStepData::$failure_reason` (`app/Enums/WorkflowStepFailureReason.php`) beside a real `exitCode`, deliberately *not* as a `skip_reason`, because `status()` lets `skip_reason` win over `exitCode` and the step would render as skipped rather than failed. Without it the throwing step was stamped `aborted` by `markUnreachedStepsAborted()`, indistinguishable from the steps behind it that genuinely never ran. Progress is pushed to the UI by broadcasting on the NativePHP channel (`app/Enums/BroadcastChannel.php`): `WorkflowStarted`, `WorkflowStepStarted/Finished/Skipped`, `WorkflowStepOutputUpdated` (throttled to 1/sec with a final flush), `WorkflowFinished`, plus `ProjectDataUpdated` for workspace-list refreshes. Final status = `ERROR` on failure, else the workflow's `ending_status`, else the status the workspace held before dispatch (`RunWorkflow::$statusBeforeRun`, since `dispatchWorkflow()` has already written `PENDING` by the time the job reads the file).

### CLI tools (`lf`) and deep links

`extras/bin/lf` is a bash script the user symlinks onto their PATH from `InstallCliToolsWidget` (falling back to `osascript … with administrator privileges` when the plain `ln -sf` is denied). The widget is always visible; a successful install persists as `SettingsData::$cli_tools_installed`, written by `CliToolsService::installCliTools()` itself so the flag cannot be true without an install, and its only effect is relabelling the one button to `Reinstall CLI tools`. It supports `lf add-project`, `lf run <workflow>` and `lf validate <workflow>`.

The script does not talk to the app over HTTP. It writes the request to `~/.laborforest/pending.yaml` (`command`, `path` = `$PWD`, optional `workflow`) and then fires `open laborforest://…` — **the deeplink is only a wake/focus trigger**; the request travels through the file. The scheme comes from `deeplink_scheme` in `config/nativephp.php`; there is no route handler (`routes/web.php` is empty).

Both drain paths call `CliToolsService::runPendingCommand()`, which returns the URL to land on and never throws — failures come back as a URL carrying the message on the query string (`Dashboard::getUrl(['error' => …])`, or the project page for a `validate-workflow` validation failure), because these callers share no session with the window. `Dashboard` and `Project` both read it via `HasQueryStringNotification` (`QueryParameter::ERROR`/`SUCCESS`/`BODY`; errors are persistent):

- **warm** — `app/Listeners/RunPendingCliCommand.php` on `Native\Desktop\Events\App\OpenedFromURL`. Registered by event auto-discovery *only*; adding a manual `Event::listen` makes it fire twice (`RunPendingCliCommandTest` asserts a single binding). It navigates by window id (`WindowId::MAIN`) via `Window::all()`, because `Window::current()` asks Electron for the *focused* window and dies on `null.id`.
- **cold** — `NativeAppServiceProvider::boot()` drains the file *before* `Window::open()`. macOS fires open-url before the PHP server is listening and NativePHP's `notifyLaravel()` swallows the failure, so the event never arrives on a cold launch.

`pullPendingCommand()` deletes `pending.yaml` before parsing it, so a malformed file cannot wedge every future launch and a deeplink arriving after the boot drain finds nothing left to run.

### MCP server

`routes/ai.php` registers one `Mcp::web` server (`app/Mcp/Servers/LaborForestServer.php`) at `McpEndpoint::LABORFOREST`. It is **not** served by the app window's process: `McpService::startMcpServer()` spawns a second Laravel HTTP server as a persistent NativePHP child — `artisan serve --no-reload --host=127.0.0.1 --port={mcp_port}` — with `LABORFOREST_MCP_SERVER=1` in its environment. `--no-reload` is load-bearing (see the docblock: ServeCommand's `$passthroughVariables` would otherwise drop every `NATIVEPHP_*` key from the process that actually answers requests).

`artisan serve` serves the *whole* application, and the Filament panel sits at `->path('')` with no auth guard, so the security of that port is entirely in middleware:

- `AppServiceProvider::allowExternalAccessToMcpServer()` **swaps** NativePHP's `PreventRegularBrowserAccess` for `AllowOnlyMcpRequests`, which 404s every path but the MCP endpoint. Swap, never remove — removing it publishes the app UI on the MCP port.
- `EnsureMcpRequestIsLocal` rejects a `Host` or `Origin` that is not loopback. This is the MCP spec's DNS-rebinding requirement: rebinding makes an attacker's page same-origin, so no CORS preflight happens, but the attacker's name still travels in `Host`. A loopback `Origin` passes because `mcp:inspector` is a browser app.
- `EnsureMcpTokenIsValid` compares `bearerToken()` against `SettingsData::$mcp_token` with `hash_equals`, 401 (not 403) so the package's `AddWwwAuthenticateHeader` reads as a challenge. A blank stored token denies everything.
- `throttle:mcp`, defined in `AppServiceProvider::boot()`.

The token is generated lazily by `McpService::startMcpServer()` and lives in `settings.yaml`, which `SettingsService::saveSettings()` writes `private` (0600). It is deliberately **not** encrypted: `APP_KEY` ships world-readable inside the app bundle and is identical on every install, so `encrypt()` would put the lock beside its key; the file mode is what actually excludes another account. `SettingsData::toMcpResource()` withholds the token so it never reaches a transcript.

Defaults are closed: `mcp_enabled = false`, `mcp_read_only = true`. Read-only is enforced by `shouldRegister()` from `Concerns\Mcp\RegistersWhenWritable`, applied to every tool that changes anything — including the three `Launch*Tool`s, which spawn a user-configured command. The answer is memoized by `McpService::isReadOnly()`, and `McpService` is bound **scoped** in `AppServiceProvider::register()` for exactly that reason: without it, each of the 12 gated tools re-parses `settings.yaml` on every request. It fails open (an unreadable settings file publishes everything) so a broken file never looks like a deliberately short tool list.

Tool annotations are what a client gates its confirmation prompts on, so they are not decorative: `run-workflow` is `#[IsDestructive]` because a step is arbitrary shell, the launch tools carry *no* annotation rather than `#[IsReadOnly]`, and `override-workspace-status` is `#[IsIdempotent]` so an agent clearing the `error` a failed run left behind does not have to interrupt the user.

### Services (`app/Services/`)

- `GitService` — worktree/branch/status operations via the git CLI
- `ProjectsService` — projects.yaml CRUD, workspace lifecycle, initializes `.laborforest/`, and seeds a user-chosen starter workflow set from `extras/example-workflows/{bare,javascript,laravel}` (`listExampleWorkflowPaths()` / `initializeWorkspaceStarterWorkflows()`)
- `WorkflowService` — loads/validates workflow YAML, builds run logs, gates and dispatches jobs
- `CliToolsService` — installs `lf`, drains and executes `pending.yaml` (see CLI tools above)
- `VariableReplacementService` — resolves `{{ }}` placeholders (`app/Enums/Variable.php`), including dynamic `ENV_*` read from the workspace's own `.env`
- `ProcessEnvironmentService::sanitized()` — strips LaborForest's own env from spawned processes (`app/Enums/HostEnvKey.php`) so workspace commands don't inherit this app's `.env`
- `LaunchService` — opens the configured IDE/browser/terminal for a workspace
- `SettingsService` — settings.yaml load/save/sync
- `GitHubService` — the newest published release, from `config('app.latest_release_url')`. The answer is held for `CacheKey::LATEST_RELEASE->ttl()` (15 minutes) because `AppVersionWidget` asks on every dashboard render and the unauthenticated GitHub API allows 60 calls an hour; failures escape `Cache::remember()` and are never cached. `getLatestReleaseData(bypassCache: true)` forces a fresh check. The `GitHubReleaseData` itself is cached, which is only safe because the boot-time `Cache::flush()` below means no entry outlives the version that wrote it — otherwise a stale serialized object of a class that has since gained a property would fail on property access, past the caller's `rescue()`

Each service throws typed exceptions from `app/Exceptions/` (`GitStatusNotClean`, `InvalidWorkflowFile`, `UnresolvedVariable`, …). Magic strings live in backed enums in `app/Enums/`.

### Gotchas

- `AppServiceProvider::hardenNativeDatabaseConnection()` re-applies WAL/busy_timeout/IMMEDIATE to the `nativephp` sqlite connection because NativePHP rewrites it at boot; removing this brings back non-retryable "database is locked" errors in queue pop.
- `vendor/nativephp/desktop/resources/build/app/` and `nativephp/electron/dist/mac-arm64/LaborForest.app/Contents/Resources/build/app/` each contain a stale copy of this entire application (including `.claude/skills` and `.junie/skills`) — grep hits there are misleading duplicates of the real files.
- `NativeAppServiceProvider::boot()` calls `Cache::flush()` before anything else. The cache store is a file store inside `~/Library/Application Support/laborforest-dev/storage`, which survives an app upgrade, so emptying it at launch binds every cached value to a single run and keeps one from being read back into code that no longer understands its shape. A listener on `Native\Desktop\Events\App\ApplicationBooted` would not do — that event travels over HTTP and is lost on a cold launch, exactly like the deeplink.
- laravel-data structure caching is disabled in `config/data.php`. `php artisan optimize` (native:build prebuild) runs `data:cache-structures`, and the native runtime rebinds `storage_path()` to `~/Library/Application Support/laborforest-dev/storage` — terminal `optimize:clear` writes to the project storage and never reaches that copy, so a cached structure silently drops properties added to `app/Data/` classes.
- Frontend is minimal: `resources/js/app.js` is empty; all UI is server-rendered Filament/Livewire. Tailwind v4 is CSS-first (no tailwind.config.js) — theme config in `resources/css/app.css` and `resources/css/filament/app/theme.css`, which safelists dynamically-built status color classes via `@source inline(...)`.
- `AppServiceProvider` registers two Filament render hooks: `TOPBAR_END` → `filament/global/refresh.blade.php`, a one-line wrapper around the `RefreshButton` Livewire component, and `BODY_END` → `WorkflowNotifications` (listens for `native:App\Events\WorkflowFinished`). `RefreshButton` is a component rather than a bare button because a hook renders inside whichever page is showing, so a plain `wire:click` would have to land on every page class in turn; its click awaits `$wire.flushCache()` before reloading, so the fresh page is not answered from the cache entry it was meant to refresh. The remaining Livewire component, `WorkflowLogStep` (ANSI→HTML step output), is *not* global — it is rendered inline in `resources/views/filament/pages/workflow-log.blade.php`.
- `AGENTS.md` is a byte-identical copy of this file (Boost writes guidelines for both `claude_code` and `junie`, see `boost.json`). Edit both, or they drift.

### Testing

Pest 5, sqlite `:memory:`, sync queue; feature tests in `tests/Feature/`, `tests/Unit/` is empty. Read the project skills in `.claude/skills/` — `service-testing`, `livewire-testing`, `pest-testing` — before writing tests; they document the patterns in detail. The load-bearing conventions:

- `tests/Pest.php` runs `Process::fake([])->preventStrayProcesses()` before every feature test, so any process a test forgot to fake fails loudly instead of reaching a real shell. Neither call works alone. A test's own `Process::fake()` merges on top. `Http::fake([])->preventStrayRequests()` does the same for HTTP — `AppVersionWidget` mounts on the dashboard and calls the GitHub releases API, so without it every dashboard test would hit the network.
- `RefreshDatabase` is intentionally commented out — nothing domain-level lives in the database.
- Test doubles live in `tests/Fakes/` (`ProcessSpy`, `FakeProcessEnvironmentService`, `FakeRunPendingCliCommand`); YAML fixtures in `tests/Fixtures/`. Shared Filament/Livewire fixture builders sit in `tests/Pest.php` prefixed `component*`, because top-level test functions share one global namespace across the suite.
- Deliberate seams are `protected` methods: `LaunchService::launchProcess()` (its `create_new_console` + faked `PendingProcess::start()` combination is fatal) and `RunPendingCliCommand::navigateTo()` (no Electron in tests). `GitService::runGit()` is private and is *not* a seam — fake the Process facade instead.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- filament/filament (FILAMENT) - v5
- laravel/framework (LARAVEL) - v13
- laravel/mcp (MCP) - v0
- laravel/prompts (PROMPTS) - v0
- livewire/livewire (LIVEWIRE) - v4
- laravel/boost (BOOST) - v2
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

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

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
