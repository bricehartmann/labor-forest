---
name: service-testing
description: "Use this skill whenever writing, editing, fixing, or reviewing a Pest feature test for a class in app/Services/ — GitService, ProjectsService, WorkflowService, SettingsService, LaunchService, VariableReplacementService, ProcessEnvironmentService. Trigger on any request to test a service, cover a service method, add failure cases for a service, fake or mock the git calls, avoid spawning shell commands in tests, reach the suite-wide user_home fake, or stub the filesystem for a service. Also trigger when a service test fails with 'Disk [extras] does not have a configured driver', with 'Attempted process [...] without a matching fake', with a BadMethodCallException from File::isFile during a spawned process, or when deciding where a test double should live. Covers: the ProcessSpy doubling pattern over Process::fake(), the one surviving LaunchService seam, container-bound collaborators, the suite-wide user_home fake, File facade stubbing, success plus failure coverage, and the no-preexisting-state rule. Do not use for Filament pages, Livewire components, jobs, or app/Data/ DTOs."
license: MIT
metadata:
  author: labor-forest
---

# Service Testing

How to test the classes in `app/Services/`. These services are the entire domain layer — there are no
models and no domain tables — and every one of them reaches the filesystem, a shell, or both. This skill
covers only how to double those boundaries.

Pest mechanics (`make:test`, `describe`/`it`, datasets, run commands) belong to the `pest-testing` skill
and are not repeated here.

`tests/Feature/GitServiceTest.php` is the reference implementation of everything below. Read it before
writing a new service test.

## Non-Negotiables

**1. No real subprocess.** A test must never spawn `git` or a launch command. Every service now runs
processes through `Illuminate\Support\Facades\Process`, so `Process::fake()` intercepts them — double
there, via [`ProcessSpy`](#the-processspy-pattern). `tests/Pest.php` installs
`Process::fake([])->preventStrayProcesses()` for every Feature test, so a process you forgot to fake
fails with `Attempted process [...] without a matching fake.` rather than reaching a shell.

**2. No real filesystem.** No temp directories, no `sys_get_temp_dir()`, no writing under the repo. Use
fixed absolute paths as *strings only* (`/tmp/repo`, `/tmp/repo-feature`) and double the filesystem.
`GitServiceTest` never creates a directory.

**3. No preexisting state, of any kind.**

- **Database**: no service reads or writes a domain table. The only table is `jobs`, and it exists solely
  as the queue backend. Leave `RefreshDatabase` commented out in `tests/Pest.php`, and never assert
  against the database.
- **Disk**: never touch the developer's real `~/.laborforest/`. `user_home` is registered at runtime by
  NativePHP and does not exist in a test boot, so `Tests\TestCase::createApplication()` registers a fake
  one *before the container bootstraps* — the Filament panel provider reads the disk while it registers,
  earlier than any hook could fake it — and `tests/Pest.php` re-fakes the same root before every test to
  empty it. So a service test needs no `Storage::fake('user_home')` of its own unless it wants the
  handle: `$this->disk = Storage::fake('user_home')`, as `SettingsServiceTest` and `ProjectsServiceTest`
  do. `extras` is not faked globally — fake it per file.
- A file the test never wrote reads as **empty, not missing**: `SettingsService::loadSettings()` seeds a
  defaults `settings.yaml` and returns defaults rather than throwing. To test a *failure* of one of these
  services from another test, mock the service and throw; do not try to take the disk away.

**4. Every public method needs both a success test and a failure test.** See the
[per-service table](#per-service-seams) for what failure means in each case.

Tests live in `tests/Feature/<Service>Test.php`. `tests/Pest.php` binds `TestCase` to `Feature` only — a
test in `tests/Unit` has no application container and cannot resolve or bind anything.

## The ProcessSpy Pattern

Instantiate the **real** service and fake the processes underneath it with
`Tests\Fakes\ProcessSpy::install()`.

<!-- Installing the spy -->
```php
beforeEach(function () {
    $this->git = new GitService;
    $this->process = ProcessSpy::install();
    $this->instance(ProcessEnvironmentService::class, new FakeProcessEnvironmentService);

    $this->repo = '/tmp/repo';
    $this->worktree = '/tmp/repo-feature';
});
```

`ProcessSpy` registers one catch-all handler closure with `Process::fake()`. Three properties make it
work:

- `$commands` is the **spy** — `[command, cwd]` pairs in call order.
- `$responses` is the **stub queue**, consumed FIFO, shaped `['ok' => bool, 'out' => ?string, 'err' => ?string]`.
- `$pending` holds each `PendingProcess`, for asserting `environment`, `timeout` or `options`.

An exhausted queue reports success with no output, so happy-path tests set no responses at all. See
the `removeWorktree`, `commitAll`, and `doesBranchExist` describes in `GitServiceTest`.

### Why a closure handler rather than plain `Process::fake()`

`Process::fake(['git status *' => ...])` plus `Process::assertRan()` cannot express what these suites
assert, for three reasons rooted in the framework:

- `Factory::$recorded` is protected with no getter, and an `assertRan()` closure sees one process at a
  time with no index — so **order** is unassertable, as is "these commands and nothing else".
- Matching is on the command line only, so two runs of the *same* command in **different directories**
  (`git status --porcelain` in the repo and then in a worktree) cannot be told apart or given different
  output.
- First registered pattern wins, not most specific.

The handler closure is passed the `PendingProcess` it is answering for, which recovers all of it.

### Stub the environment service too

With the process seams gone, `ProcessEnvironmentService::sanitized()` runs for real on every spawned
process — scanning `getenv()` and parsing this application's own `.env`. That reaches whatever `File`
facade mock the test installed, and a full Mockery mock throws on the unstubbed `isFile()`. Bind
`Tests\Fakes\FakeProcessEnvironmentService`, which answers with a sentinel;
`ProcessEnvironmentServiceTest` covers the real behaviour.

### Where a Fake Lives

Keep a fake at the bottom of its own test file until a second file needs it, then extract it to
`tests/Fakes/` under namespace `Tests\Fakes`. `"Tests\\": "tests/"` is already in `composer.json`
`autoload-dev`, so no composer change is needed.

## The One Surviving Seam: LaunchService

`LaunchService::launchProcess()` is the single exception to "double with `Process::fake()`", and it is
not a matter of taste. `LaunchService` calls `start()`, and it must set
`options(['create_new_console' => true])` — without that option Symfony's `Process::__destruct()` stops
the process as `launch()` returns, killing the editor or terminal that was just opened.

But `PendingProcess::start()` builds a Symfony process *before* it checks for a fake, then discards it
unstarted. With `create_new_console` set, that discarded process's destructor reads pipes which were
never opened:

```
Error: Typed property Symfony\Component\Process\Process::$processPipes
must not be accessed before initialization
```

That is a fatal, not a failing assertion — so `LaunchService` and `Process::fake()` cannot meet. Keep
`launchProcess()` as a protected seam, override it in `FakeLaunchService`, and inspect the real one
through `ExposedLaunchService` (both at the bottom of `LaunchServiceTest`).

**Do not add a seam anywhere else.** For every other service, fake the process.

## Doubling Collaborators

No service has a constructor — collaborators are pulled inline with `app(GitService::class)`, and nothing
is registered as a singleton (`AppServiceProvider::register()` is empty), so the container builds a fresh
instance per resolve.

A collaborator that only shells out needs no double at all: let the real one run and fake the process
beneath it. `ProjectsServiceTest` does exactly this — it binds no `GitService`.

<!-- Faking a collaborator's processes rather than the collaborator -->
```php
beforeEach(function () {
    $this->process = ProcessSpy::install();
    $this->instance(ProcessEnvironmentService::class, new FakeProcessEnvironmentService);
});

it('lists the workspaces of a project', function () {
    $this->process->responses = [['ok' => true, 'out' => "worktree /tmp/repo\nHEAD aaa111\nbranch refs/heads/main\n"]];

    $workspaces = app(ProjectsService::class)->loadProjectWorkspaces('/tmp/repo');

    expect($workspaces)->toHaveCount(1)
        ->and($this->process->commands)->toBe([
            ['git worktree list --porcelain', '/tmp/repo'],
        ]);
});
```

Prefer this over `$this->mock(GitService::class)` — one doubling style across the suite, and command-
sequence spying comes free.

> `AppPanelProvider::panel()` calls `ProjectsService::loadProjects()` and `SettingsService::loadSettings()`
> at **boot** time, wrapped in `rescue()`. Anything bound inside a test body is too late to affect panel
> navigation — that boot runs against the suite's empty `user_home` and reports nothing. To cover
> `panel()` itself, invoke it directly over mocked services, as `AppPanelProviderTest` does.

## Per-Service Doubles

| Service | What to double | Failure cases to cover |
|---------|-----------------|------------------------|
| `GitService` | `ProcessSpy::install()`; `FakeProcessEnvironmentService`; `File::shouldReceive('exists')` | `GitOperationFailed` for each operation label; `WorkspaceDirectoryExists`; `GitBranchDoesNotExist`; the bare `RuntimeException` when neither the branch exists nor a base branch is given |
| `SettingsService` | the globally faked `user_home` disk — no other seam | `InvalidSettingsFile::fromParseError` / `notAMapping` / `fromValidation`. A null or empty file yields defaults via the merge and does **not** throw — test that too |
| `ProjectsService` | the globally faked `user_home` disk plus `Storage::fake('extras')`; `ProcessSpy::install()` (the real `GitService` runs); `FakeProcessEnvironmentService`; `File` facade for absolute workspace paths | `InvalidProjectsFile` (including `withProblems`, which accumulates *every* bad entry rather than failing on the first); `ProjectNotFound`; `ProjectDirectoryNotFound` / `ProjectDirectoryExists` / `ProjectDirectoryNotGitRepository`; `GitStatusNotClean`; `WorkspaceNotFound`. Also cover the `rescue()`d paths that swallow git failures and fall back to defaults |
| `WorkflowService` | `Queue::fake()`; bind a fake `ProjectsService`; `File` facade under `.laborforest/` | `InvalidWorkflowFile` — a missing file also throws, since `Yaml::parseFile` raises `ParseException`; `WorkspaceNotFound` propagating out of `dispatchWorkflow`. Also the non-throwing skips: missing directory returns `collect()`, wrong `resource_type` filtered out, empty-step workflows rejected |
| `VariableReplacementService` | `File::shouldReceive('isFile')` and `get()` for the workspace `.env` | `UnresolvedVariable::unknownVariable`; `::missingEnvironmentVariable` (thrown from inside the preg callback); `::replacementFailed` |
| `LaunchService` | the `launchProcess()` seam above; `FakeProcessEnvironmentService`; bind a fake `SettingsService` | Silent early return on a null or empty command; `InvalidSettingsFile` and `UnresolvedVariable` propagating through |
| `ProcessEnvironmentService` | `File::shouldReceive` for `base_path('.env')` | No typed exceptions. Assert presence and absence of **specific keys** — never whole-array equality, because `getenv()` reads the real host environment and cannot be doubled |

Two cross-cutting notes:

- `File::shouldReceive('x')` swaps the facade for a full Mockery mock, so any *other* `File` method the
  code path reaches throws `BadMethodCallException`. Stub every `File` method the path touches, or use
  `File::partialMock()` when only some calls should be intercepted.
- `Carbon::setTestNow()` is required for anything timestamp-derived: `WorkflowService::runLogId()`,
  `availableLogTimestamp()`, `dispatchWorkflow()`, and `ProjectsService::addProject()` /
  `loadProject($uuid, touch: true)`.

## Test Structure

- A top-level `beforeEach` builds the fake and the fixed path strings. A nested `beforeEach` inside a
  `describe` sets up seams specific to that method.
- One `describe()` per public method; `it()` for cases.
- Drive a per-test seam from a mutable property rather than re-mocking:

<!-- Facade stub driven by a per-test property -->
```php
beforeEach(function () {
    $this->existingPath = null;

    File::shouldReceive('exists')->andReturnUsing(fn (string $path) => $path === $this->existingPath);
});
```

- **Success case**: assert the return value *and* the recorded interaction.

<!-- Success: return value plus exact command sequence -->
```php
it('adds a worktree for a branch that already exists', function () {
    $this->git->responses = [['ok' => true], ['ok' => true]];

    $worktree = $this->git->addWorktree($this->repo, $this->worktree, 'feature', null);

    expect($worktree->branch)->toBe('feature')
        ->and($this->git->commands)->toBe([
            ['git show-ref --verify --quiet "refs/heads/feature"', $this->repo],
            ['git worktree add "/tmp/repo-feature" "feature"', $this->repo],
        ]);
});
```

- **Failure case**: assert the exception class, its exact message, *and* that nothing ran past the throw.
  A failure test that only asserts the exception is incomplete.

<!-- Failure: exception plus proof that side effects stopped -->
```php
it('throws before running git when the workspace directory already exists', function () {
    $this->existingPath = $this->worktree;

    expect(fn () => $this->git->addWorktree($this->repo, $this->worktree, 'feature', null))
        ->toThrow(WorkspaceDirectoryExists::class, "Workspace with directory '/tmp/repo-feature' already exists.")
        ->and($this->git->commands)->toBe([]);
});
```

- Use fluent `expect()->and()` chains.
- Put fixture builders at the bottom of the file with named arguments and a docblock — see
  `worktreeRecord()` in `GitServiceTest`.
- Use datasets with descriptive string keys for table-driven cases.
- Use heredocs for fixtures containing quotes.

## Common Pitfalls

- Reaching for a protected process seam — `Process::fake()` is the doubling mechanism now, and
  `LaunchService::launchProcess()` is the sole exception
- Letting the real `ProcessEnvironmentService` run: it parses this application's `.env` on every
  spawned process and collides with a `File` facade mock. Bind `FakeProcessEnvironmentService`
- Expecting a faked `run($command, $closure)` to invoke the closure — it does not. Only
  `start($command, $closure)->wait()` streams under a fake, which is why `RunWorkflow` uses it
- Forgetting that `Process::result(output: '0')` yields `''` — `normalizeOutput()` short-circuits on
  `empty()`, and `empty('0')` is true
- Reaching for `Storage::fake('user_home')` to make a `ManagesFiles` service fail — the disk is always faked now; mock the service instead
- Creating real temp directories instead of using path strings plus a doubled filesystem
- Uncommenting `RefreshDatabase` in `tests/Pest.php`, or asserting against the database at all
- Writing the test in `tests/Unit` and losing the container
- Asserting a thrown exception without asserting that side effects stopped
- Whole-array equality against `ProcessEnvironmentService::sanitized()`
- Duplicating a fake across files instead of extracting it to `tests/Fakes/`
