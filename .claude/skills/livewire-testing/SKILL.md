---
name: livewire-testing
description: "Use this skill whenever writing, editing, fixing, or reviewing a Pest feature test for a Livewire component in app/Livewire/ (WorkflowLogStep, WorkflowNotifications) or a Filament class in app/Filament/ — the Project, ProjectWorkflows, WorkflowLog and Settings pages, the AddProjectWidget and ProjectsLoadErrorWidget widgets. Trigger on any request to test a page, a widget, a page action, a table record action, a bulk action, a form save, a #[On('native:...')] broadcast listener, a #[Computed] property, a #[Locked] property, or a Filament notification. Also trigger when a component test fails with 'Disk [user_home] does not have a configured driver', 'Call to undefined function livewire()', 'Cannot redeclare function', 'Record arrays must have a unique [key] entry', a null $record in a table action closure, a BadMethodCallException from a mocked service, or a NativePHP System::timezone() / Dialog::new() call reaching the real Electron client. Covers: Livewire::test() mounting, route params as mount args, TestAction for table actions, mocking services with \\$this->mock(), fake subclasses from tests/Fakes/, test-only component subclasses as seams, Storage::fake('user_home'), and the no-preexisting-state rule. Do not use for app/Services/ (see service-testing), jobs, or app/Data/ DTOs."
license: MIT
metadata:
  author: labor-forest
---

# Livewire & Filament Component Testing

How to test the Livewire components in `app/Livewire/` and `app/Filament/`. Every one of them is a
Livewire component that pulls services inline with `app(X::class)`, and several react to `native:`
broadcast events or call NativePHP statics that do not exist outside the desktop runtime. This skill
covers how to mount those components and what to double.

Service internals belong to `service-testing`; Pest mechanics (`make:test`, `describe`/`it`, datasets,
run commands) belong to `pest-testing`. Neither is repeated here.

## Non-Negotiables

**1. No preexisting state, of any kind.**

- **Database**: nothing here reads or writes a domain table. Leave `RefreshDatabase` commented out in
  `tests/Pest.php` and never assert against the database.
- **Disk**: never touch the developer's real `~/.laborforest/`. Put `Storage::fake('user_home')` in the
  top-level `beforeEach` of every file — that disk is registered at runtime by NativePHP and **does not
  exist** in a test boot, so anything reaching it dies with
  `Disk [user_home] does not have a configured driver.`
- Every component input is built inside the test.

**2. No real filesystem and no real subprocess.** Components reach services; services reach git and
disk. Double at the *service* boundary so neither is ever reached. Paths are fixed strings
(`/tmp/repo`, `/tmp/repo-feature`) that are never created.

**3. Tests live in `tests/Feature/<Component>Test.php`.** `tests/Pest.php` binds `TestCase` to
`Feature` only — a test in `tests/Unit` has no application container and cannot bind anything.

**4. There is no `livewire()` helper.** `pestphp/pest-plugin-livewire` is not installed. Mount with
`Livewire::test(Component::class, [...])`. Filament's assertions still work — `callAction`,
`assertActionVisible`, `fillForm`, `assertHasNoFormErrors`, `assertNotified` and friends are mixed into
`Livewire\Features\SupportTesting\Testable` by the Filament service providers
(`vendor/filament/actions/src/ActionsServiceProvider.php:44` and siblings).

**5. `Filament::setCurrentPanel()` is not needed.** Filament falls back to the default panel
(`getCurrentOrDefaultPanel()`), and `AppPanelProvider` declares `->default()`. Do not add panel setup.

**6. Every public entry point needs a success test and a failure test.** For pages the failure is
almost never an exception — see [Failure Is a Message, Not a Throw](#failure-is-a-message-not-a-throw).

## Mounting

Mount arguments are the route parameters declared by `getSlug()`, in order:

| Component | Mount |
|---|---|
| `Project` | `Livewire::test(Project::class, ['uuid' => $uuid])` |
| `ProjectWorkflows` | `['uuid' => $uuid, 'slug' => 'repo-feature']` |
| `WorkflowLog` | `['uuid' => $uuid, 'slug' => 'repo-feature', 'id' => $logId]` |
| `Settings`, both widgets, `WorkflowNotifications` | no arguments |
| `WorkflowLogStep` | `['step' => [...], 'uuid' => $uuid, 'slug' => 'repo-feature']` — plain props, no `mount()` |

`WorkflowLogStep`'s view dereferences `$this->stepData` unguarded, so an empty `step` array renders a
null-property error. Always pass a step.

<!-- Plain component: props in, rendered output out -->
```php
it('renders a log step', function () {
    Livewire::test(WorkflowLogStep::class, [
        'step' => [
            'name' => 'Install dependencies',
            'type' => WorkflowStepType::SHELL->value,
            'exitCode' => 0,
            'output' => 'done',
            'hash' => 'aaa111',
        ],
        'uuid' => $this->uuid,
        'slug' => 'repo-feature',
    ])
        ->assertOk()
        ->assertSee('Install dependencies');
});
```

Prefer `Livewire::test()` over `$this->get(Page::getUrl(...))`: the HTTP route renders the whole panel
layout and needs a built Vite manifest (`npm run build`). Reach for a route-level test only for a
genuine smoke test, and say so in the test name.

## Doubling Collaborators

Services have no constructor, nothing is registered as a singleton (`AppServiceProvider::register()` is
empty), and components resolve them per call with `app(X::class)` — so a substitution registered in
`beforeEach` is picked up by the component.

**Default to `$this->mock()`**, stubbing exactly the methods the code path reaches.

<!-- The canonical page double: three services, only the methods the path touches -->
```php
$this->mock(ProjectsService::class, function (MockInterface $mock) {
    $mock->shouldReceive('loadProject')->andReturn(logProjectData($this->uuid, $this->repo));
    $mock->shouldReceive('loadProjectWorkspaces')->andReturn(collect([logWorkspaceData($this->worktree)]));
    $mock->shouldReceive('doesAnyProjectWorkspaceWorkflowExist')->andReturn(false);
    $mock->shouldReceive('initializeWorkspaceStarterWorkflows')->once()->with($this->worktree);
});

$this->mock(WorkflowService::class, function (MockInterface $mock) {
    $mock->shouldReceive('loadWorkflows')->andReturn(collect(['up' => upWorkflowData()]));
});

$this->mock(SettingsService::class, function (MockInterface $mock) {
    $mock->shouldReceive('loadSettings')->andReturn(new SettingsData);
});
```

A Mockery mock is strict: any method the path reaches that was not stubbed throws
`BadMethodCallException`. Stub the full set for the page under test:

| Page / widget | Services and methods reached |
|---|---|
| `Project` | `ProjectsService::{loadProject, loadProjectWorkspaces, listProjectLocalBranches, addProjectWorkspace, updateProject, updateProjectWorkspaceStatus, removeProject, doesAnyProjectWorkspaceWorkflowExist, initializeWorkspaceStarterWorkflows}`, `WorkflowService::{loadWorkflows, loadSteps, dispatchWorkflow}`, `SettingsService::loadSettings`, `LaunchService::{launchTerminal, launchIde, launchBrowser}`, `GitService::{status, currentBranch, commitAll, removeWorktree}` |
| `ProjectWorkflows` | `ProjectsService::{loadProject, loadProjectWorkspaces}`, `WorkflowService::loadWorkflowLogData`, plus `System::timezone()` |
| `WorkflowLog` | `ProjectsService::{loadProject, loadProjectWorkspaces}`, `WorkflowService::loadWorkflowLogDatum` |
| `Settings` | `SettingsService::{loadSettings, saveSettings}` |
| `WorkflowNotifications` | `ProjectsService::loadProject` (inside `rescue()`, for the notification body) |
| both widgets | `ProjectsService::loadProjects` (via `HasProjectsLoadError`) |

`SettingsService::loadSettings()` is called while the table and its actions are **built**, not only in
`mount()` — stub it in every `Project` and `Settings` test, including failure tests.

**Escalate to a fake subclass in `tests/Fakes/`** when the test asserts *which* calls happened or in
what order, or when one method must answer differently across calls. `tests/Fakes/FakeGitService.php`
is the model; bind it with `$this->instance(GitService::class, $this->git)`. Extract a new fake to
`tests/Fakes/` only once a second file needs it — `"Tests\\": "tests/"` is already in `composer.json`
`autoload-dev`.

## Failure Is a Message, Not a Throw

Every page swallows load failures into `$loadedInvalidMessage`. The failure test asserts that property
and its exact message — expecting a throw is wrong.

<!-- Failure path: the page reports, it does not blow up -->
```php
it('records the load failure instead of throwing', function () {
    $this->mock(ProjectsService::class, function (MockInterface $mock) {
        $mock->shouldReceive('loadProject')->andThrow(new ProjectNotFound($this->uuid));
    });

    $this->mock(SettingsService::class, function (MockInterface $mock) {
        $mock->shouldReceive('loadSettings')->andReturn(new SettingsData);
    });

    Livewire::test(Project::class, ['uuid' => $this->uuid])
        ->assertOk()
        ->assertSet('loadedInvalidMessage', "Project with UUID '{$this->uuid}' not found.");
});
```

Action failures surface as a danger notification instead: assert `->assertNotified('Whoops! Something
went wrong.')` **and** that the side effect never happened (`->assertSet(...)` unchanged, or a fake's
spy array still empty). An action failure test that only asserts the notification is incomplete.

## Actions

Page actions are called by name. Table record actions need `TestAction` and the **record key**.

Filament assigns array records a `__key` equal to their index in the records array
(`vendor/filament/tables/src/Concerns/HasRecords.php:129`), and `$this->workspaces` /
`$this->workflowLogData` carry no key of their own. So pass the index as a string; passing the record
array itself only works if that array contains `__key`, otherwise the action closure receives `null`.

<!-- Table record action: keyed by array index, asserted through its notification -->
```php
Livewire::test(Project::class, ['uuid' => $this->uuid])
    ->assertOk()
    ->callAction(TestAction::make('create_starter_workflows')->table('0'))
    ->assertNotified('Workflows created: up & down');
```

Actions inside an `ActionGroup` are addressed by their own name — the group is not part of the path.
Bulk actions add `->bulk()`. An action with a modal form takes that form's data as `callAction`'s second
argument, and the two submit buttons of a workflow action are chosen with `->arguments()`:

<!-- Modal action: arguments pick the submit button, the array is the modal form's data -->
```php
Livewire::test(Project::class, ['uuid' => $this->uuid])
    ->callAction(
        TestAction::make('workflow_up')->table('0')->arguments(['watch' => true]),
        ['step_'.$step->hash('0') => true],
    )
    ->assertNotified('Up workflow started')
    ->assertRedirect('/projects/'.$this->uuid.'/workspaces/repo-feature/logs/'.$logId);
```

Workflow actions are named `workflow_<name>` (`workflow_up`) and their checkboxes are named
`step_<hash>`, where the hash comes from `WorkflowStepData::hash((string) $index)` — build it from the
same step object the mocked `loadSteps()` returns rather than hard-coding a hash.

Forms are filled and submitted directly:

<!-- Form page: fill, save, assert the service received the parsed data -->
```php
it('saves settings', function () {
    $this->mock(SettingsService::class, function (MockInterface $mock) {
        $mock->shouldReceive('loadSettings')->andReturn(new SettingsData);
        $mock->shouldReceive('saveSettings')->once()->withArgs(
            fn (SettingsData $settings) => $settings->workflow_timeout_seconds === 90
        );
    });

    Livewire::test(Settings::class)
        ->fillForm(['workflow_timeout_seconds' => 90])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Settings saved');
});
```

## Broadcast Listeners

`#[On('native:'.SomeEvent::class)]` handlers are driven with `->dispatch()` using the same string and
named arguments matching the handler's parameters. Nothing in these components *broadcasts*, so no
`Event::fake()` is needed; `BROADCAST_CONNECTION=null` is already set in `phpunit.xml`.

<!-- Listener: dispatch the native event, assert the toast -->
```php
Livewire::test(WorkflowNotifications::class)
    ->dispatch(
        'native:'.WorkflowFinished::class,
        projectUuid: $this->uuid,
        workspaceSlugKebab: 'repo-feature',
        workflowName: 'up',
        status: WorkflowStatus::SUCCESS->value,
        logId: 'log-1',
    )
    ->assertNotified('Workflow succeeded');
```

Cover the guard clauses too — an unknown `status`, a null `projectUuid`, and a `RUNNING` status must all
end in `->assertNotNotified()`.

`WorkflowLog` responds to a step event by reloading and dispatching a browser event; assert both.
Step output is rendered by the child `WorkflowLogStep` component, so it never appears in the parent's
HTML — assert the parent's state, not `assertSee`.

<!-- Streamed output is patched into state, not into this component's HTML -->
```php
->dispatch(
    'native:'.WorkflowStepOutputUpdated::class,
    projectUuid: $this->uuid,
    workspaceSlugKebab: 'repo-feature',
    workflowName: 'up',
    stepHash: 'aaa111',
    output: 'installing…',
)
->assertDispatched('scroll-to-step', stepHash: 'aaa111')
->assertSet('workflowRunLog.steps.0.output', 'installing…');
```

## Seams for What Cannot Be Doubled

**`#[Locked]` properties** cannot be written with `->set()`. Pass them as mount arguments, or — when the
component computes them itself — subclass the component in the test file and override the method that
computes them. `Livewire::test()` accepts any component class, including one declared in the test file.

`WorkflowNotifications::mount()` reads `request()->path()`, which under `Livewire::test()` is
`livewire-unit-test-endpoint/<random>` and can never match a log URL. The suppression branch is reached
through a subclass:

<!-- Test-only subclass: the seam for a value mount() derives from the request -->
```php
/**
 * A WorkflowNotifications already parked on the log page of the run that finishes.
 */
class ViewingLogNotifications extends WorkflowNotifications
{
    public function mount(): void
    {
        $this->mountedPath = 'projects/'.self::UUID.'/workspaces/repo-feature/logs/log-1';
    }
}
```

**NativePHP.** `Native\Desktop\Facades\System` is a real facade, so
`System::shouldReceive('timezone')->andReturn('UTC')` is enough — and is mandatory for every
`ProjectWorkflows` test, since `mount()` calls it and the real call talks to Electron.

`Native\Desktop\Dialog::new()` in `AddProjectWidget::addProjectAction()` is a static constructor on a
concrete class — not a facade, not container-resolved, not overridable by a macro. Before testing that
widget, extract the picker into a protected seam and override it in a test subclass. This is the only
production change this skill asks for, mirroring `service-testing`'s `launchProcess()`:

<!-- The seam to add before testing AddProjectWidget -->
```php
/**
 * The directory picker, isolated so a test can choose a path without opening a native dialog.
 */
protected function selectProjectDirectory(): ?string
{
    return Dialog::new()
        ->title('Select Project Directory')
        ->folders()
        ->open();
}
```

## Panel And Widget Gotchas

- `AppPanelProvider::panel()` calls `ProjectsService::loadProjects()` and `SettingsService::loadSettings()`
  at **boot**, wrapped in `rescue()` — long before any test body runs. Container substitutions cannot
  affect panel navigation, so never assert on nav items.
- Widget visibility is a static call: `ProjectsLoadErrorWidget::canView()` and `AddProjectWidget::canView()`
  go through `HasProjectsLoadError::projectsLoadErrorMessage()` to a freshly resolved `ProjectsService`.
  Drive it by mocking `loadProjects()`, then assert `canView()` directly — mounting is not required for
  visibility, only for `loadedInvalidMessage`.
- `#[Computed]` properties are asserted through behavior (`assertSee`, a notification, a dispatched
  event), never read off the component.

## Test Structure

- A top-level `beforeEach` calls `Storage::fake('user_home')` and builds the fixed uuid and path
  strings; a nested `beforeEach` inside a `describe` sets up the doubles for that action or listener.
- One `describe()` per action, listener, or public method; `it()` for cases.
- Success case asserts state **and** interaction (`->once()->with(...)` on the mock, or a fake's spy array).
- Fixture builders live at the bottom of the file with named arguments and a docblock.
- **Fixture builder names are global across the whole suite** — `tests/Feature/*.php` share one function
  namespace, and `projectData()`, `workspaceData()`, `runLogData()`, `shellStep()` are already taken.
  Prefix new ones with the component (`logProjectData()`, `notificationWorkspaceData()`) or the run dies
  with `Cannot redeclare function`.
- `Carbon::setTestNow()` for anything timestamp-derived, reset in `afterEach`.

## Common Pitfalls

- Calling `livewire()` — the plugin is not installed; use `Livewire::test()`
- Adding `Filament::setCurrentPanel()` — unnecessary, the default panel resolves
- `->set()` on a `#[Locked]` property instead of mounting it or subclassing
- Forgetting `Storage::fake('user_home')`
- Forgetting `SettingsService::loadSettings` on a `Project` or `Settings` test, including its failure test
- Expecting a page to throw instead of asserting `loadedInvalidMessage`
- `TestAction::make(...)->table($record)` with a record array that has no `__key` — pass the index string
- `assertSee` for step output that a child component renders
- Un-stubbed `System::timezone()` reaching the Electron client
- A fixture builder named `projectData()` / `workspaceData()`, colliding with `VariableReplacementServiceTest`
- Writing the test in `tests/Unit` and losing the container
