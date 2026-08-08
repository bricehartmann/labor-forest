---
name: service-testing
description: "Use this skill whenever writing, editing, fixing, or reviewing a Pest feature test for a class in app/Services/ — GitService, ProjectsService, WorkflowService, SettingsService, LaunchService, VariableReplacementService, ProcessEnvironmentService. Trigger on any request to test a service, cover a service method, add failure cases for a service, fake or mock the git calls, avoid spawning shell commands in tests, fake the user_home disk, or stub the filesystem for a service. Also trigger when a service test fails with 'Disk [user_home] does not have a configured driver', when a test appears to spawn a real git process, or when deciding where a test double should live. Covers: the fake-subclass doubling pattern, protected process seams, container-bound collaborators, Storage::fake('user_home'), File facade stubbing, success plus failure coverage, and the no-preexisting-state rule. Do not use for Filament pages, Livewire components, jobs, or app/Data/ DTOs."
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

**1. No real subprocess.** A test must never spawn `git` or a launch command. Services build
`Symfony\Component\Process\Process` directly, so Laravel's `Process::fake()` does not intercept them —
double at the protected seam instead (see [Adding a Seam](#adding-a-seam-when-one-is-missing)).

**2. No real filesystem.** No temp directories, no `sys_get_temp_dir()`, no writing under the repo. Use
fixed absolute paths as *strings only* (`/tmp/repo`, `/tmp/repo-feature`) and double the filesystem.
`GitServiceTest` never creates a directory.

**3. No preexisting state, of any kind.**

- **Database**: no service reads or writes a domain table. The only table is `jobs`, and it exists solely
  as the queue backend. Leave `RefreshDatabase` commented out in `tests/Pest.php`, and never assert
  against the database.
- **Disk**: never touch the developer's real `~/.laborforest/`. `Storage::fake('user_home')` is mandatory
  for any service using the `ManagesFiles` trait — that disk is registered at runtime by NativePHP and
  **does not exist** in a test boot. Without the fake the test dies with
  `Disk [user_home] does not have a configured driver.`

**4. Every public method needs both a success test and a failure test.** See the
[per-service table](#per-service-seams) for what failure means in each case.

Tests live in `tests/Feature/<Service>Test.php`. `tests/Pest.php` binds `TestCase` to `Feature` only — a
test in `tests/Unit` has no application container and cannot resolve or bind anything.

## The Fake-Subclass Pattern

The house pattern for a service that shells out: subclass it, override **only** the protected seam, and
let every line of production logic run.

<!-- Fake subclass with spy and stub queue -->
```php
/**
 * A GitService whose process construction is replaced by a queue of canned results, so no git
 * binary is ever spawned and the exact command and working directory can be asserted.
 */
final class FakeGitService extends GitService
{
    /**
     * Every command handed to gitProcess(), as a [command, cwd] pair.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    public array $commands = [];

    /**
     * The results each successive gitProcess() call reports, consumed first in first out.
     *
     * @var array<int, array{ok: bool, out?: string, err?: string}>
     */
    public array $responses = [];

    protected function gitProcess(string $command, string $cwd): Process
    {
        $this->commands[] = [$command, $cwd];

        $response = array_shift($this->responses) ?? ['ok' => true];

        $process = Mockery::mock(Process::class);
        $process->allows('run')->andReturns(0);
        $process->allows('isSuccessful')->andReturns($response['ok']);
        $process->allows('getOutput')->andReturns($response['out'] ?? '');
        $process->allows('getErrorOutput')->andReturns($response['err'] ?? '');

        return $process;
    }
}
```

Four properties make this work:

- `$commands` is the **spy**. Interaction assertions read from it, not from Mockery expectations.
- `$responses` is the **stub queue**, consumed FIFO.
- `?? ['ok' => true]` is deliberate — happy-path tests set no responses at all. See the `removeWorktree`,
  `commitAll`, and `doesBranchExist` describes in `GitServiceTest`.
- Mockery appears only for the leaf `Process`, and only via `allows()` — stubs, never expectations.

Docblock the class and both properties with array shapes.

### Where a Fake Lives

Keep the fake at the bottom of its own test file until a second file needs it, then extract it to
`tests/Fakes/FakeGitService.php` under namespace `Tests\Fakes`. `"Tests\\": "tests/"` is already in
`composer.json` `autoload-dev`, so no composer change is needed.

Writing `ProjectsServiceTest` will need `FakeGitService` and therefore triggers that extraction.

## Adding a Seam When One Is Missing

`GitService::gitProcess()` exists precisely so process construction can be overridden. `LaunchService`
has no equivalent — it builds `Process::fromShellCommandline(...)` inline inside `protected launch()`,
which is also the method under test, so overriding it would delete the logic being tested.

**Before testing a service that constructs a `Process` inline, extract a protected factory method
mirroring `GitService::gitProcess()`.** For `LaunchService` that is:

<!-- The seam to add before testing LaunchService -->
```php
protected function launchProcess(string $command, string $cwd): Process
{
    return Process::fromShellCommandline(
        command: $command,
        cwd: $cwd,
        env: app(ProcessEnvironmentService::class)->sanitized(),
    );
}
```

This is the only production change this skill ever asks for. It keeps the doubling strategy uniform
across `app/Services/`.

## Doubling Collaborators

No service has a constructor — collaborators are pulled inline with `app(GitService::class)`, and nothing
is registered as a singleton (`AppServiceProvider::register()` is empty), so the container builds a fresh
instance per resolve. Substitute by binding the fake subclass:

<!-- Binding a fake collaborator into the container -->
```php
beforeEach(function () {
    $this->git = new FakeGitService;
    $this->instance(GitService::class, $this->git);
});

it('lists the workspaces of a project', function () {
    $this->git->responses = [['ok' => true, 'out' => "worktree /tmp/repo\nHEAD aaa111\nbranch refs/heads/main\n"]];

    $workspaces = app(ProjectsService::class)->loadProjectWorkspaces('/tmp/repo');

    expect($workspaces)->toHaveCount(1)
        ->and($this->git->commands)->toBe([
            ['git worktree list --porcelain', '/tmp/repo'],
        ]);
});
```

Prefer this over `$this->mock(GitService::class)` — one doubling style across the suite, and command-
sequence spying comes free.

> `AppPanelProvider::panel()` calls `ProjectsService::loadProjects()` and `SettingsService::loadSettings()`
> at **boot** time, wrapped in `rescue()`. Anything bound inside a test body is too late to affect panel
> navigation. Irrelevant to service tests; fatal to anyone who assumes otherwise.

## Per-Service Seams

| Service | Seams to double | Failure cases to cover |
|---------|-----------------|------------------------|
| `GitService` | `gitProcess()` via `FakeGitService`; `File::shouldReceive('exists')` | `GitOperationFailed` for each operation label; `WorkspaceDirectoryExists`; `GitBranchDoesNotExist`; the bare `RuntimeException` when neither the branch exists nor a base branch is given |
| `SettingsService` | `Storage::fake('user_home')` — no other seam | `InvalidSettingsFile::fromParseError` / `notAMapping` / `fromValidation`. A null or empty file yields defaults via the merge and does **not** throw — test that too |
| `ProjectsService` | `Storage::fake('user_home')`; bind `FakeGitService`; `File` facade for absolute workspace paths | `InvalidProjectsFile` (including `withProblems`, which accumulates *every* bad entry rather than failing on the first); `ProjectNotFound`; `ProjectDirectoryNotFound` / `ProjectDirectoryExists` / `ProjectDirectoryNotGitRepository`; `GitStatusNotClean`; `WorkspaceNotFound`. Also cover the `rescue()`d paths that swallow git failures and fall back to defaults |
| `WorkflowService` | `Queue::fake()`; bind a fake `ProjectsService`; `File` facade under `.laborforest/` | `InvalidWorkflowFile` — a missing file also throws, since `Yaml::parseFile` raises `ParseException`; `WorkspaceNotFound` propagating out of `dispatchWorkflow`. Also the non-throwing skips: missing directory returns `collect()`, wrong `resource_type` filtered out, empty-step workflows rejected |
| `VariableReplacementService` | `File::shouldReceive('isFile')` and `get()` for the workspace `.env` | `UnresolvedVariable::unknownVariable`; `::missingEnvironmentVariable` (thrown from inside the preg callback); `::replacementFailed` |
| `LaunchService` | the `launchProcess()` seam above; bind a fake `SettingsService` | Silent early return on a null or empty command; `InvalidSettingsFile` and `UnresolvedVariable` propagating through |
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

- Reaching for `Process::fake()` — it does not intercept `Symfony\Component\Process\Process`
- Forgetting `Storage::fake('user_home')` on a `ManagesFiles` service
- Creating real temp directories instead of using path strings plus a doubled filesystem
- Uncommenting `RefreshDatabase` in `tests/Pest.php`, or asserting against the database at all
- Writing the test in `tests/Unit` and losing the container
- Asserting a thrown exception without asserting that side effects stopped
- Whole-array equality against `ProcessEnvironmentService::sanitized()`
- Duplicating a fake across files instead of extracting it to `tests/Fakes/`
