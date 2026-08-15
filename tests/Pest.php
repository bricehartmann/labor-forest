<?php

use App\Data\ProjectData;
use App\Data\WorkflowData;
use App\Data\WorkflowRunLogData;
use App\Data\WorkflowRunLogStepData;
use App\Data\WorkflowStepData;
use App\Data\WorkspaceData;
use App\Enums\GitStatus;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowStepSkipReason;
use App\Enums\WorkflowStepType;
use App\Enums\WorkspaceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Turns recording on while registering no handlers, so any process a test forgot to fake
        // fails loudly instead of reaching a shell. Neither call works alone: preventStrayProcesses()
        // does nothing until something is recording, and a bare Process::fake() installs a catch-all
        // handler that would swallow everything. A test's own Process::fake() merges on top of this.
        Process::fake([])->preventStrayProcesses();
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Component Fixture Builders
|--------------------------------------------------------------------------
|
| Shared by the Livewire and Filament component tests in tests/Feature. They live here rather than at
| the bottom of any single test file because several files need them and top-level test functions share
| one global namespace across the whole suite. Every name is prefixed with "component" so it cannot
| collide with the service tests' own builders.
|
*/

/**
 * A project rooted at a fixed path that is never created on disk.
 */
function componentProjectData(
    string $uuid,
    string $path = '/tmp/repo',
    int $lastOpened = 1704067200,
    ?string $ide = null,
    ?string $browser = null,
    ?string $terminal = null,
): ProjectData {
    return new ProjectData(
        uuid: $uuid,
        path: $path,
        last_opened: $lastOpened,
        command_launch_ide: $ide,
        command_launch_browser: $browser,
        command_launch_terminal: $terminal,
    );
}

/**
 * A workspace whose slugKebab() is the basename of its path.
 */
function componentWorkspaceData(
    string $path = '/tmp/repo-feature',
    bool $isPrimary = false,
    string $branch = 'feature',
    WorkspaceStatus $status = WorkspaceStatus::READY,
    GitStatus $gitStatus = GitStatus::CLEAN,
): WorkspaceData {
    return new WorkspaceData(
        is_primary: $isPrimary,
        path: $path,
        branch: $branch,
        status: $status,
        git_status: $gitStatus,
    );
}

/**
 * A workflow definition with the given steps.
 *
 * @param  array<int, WorkflowStepData>  $steps
 */
function componentWorkflowData(
    array $steps = [],
    int $sortOrder = 0,
    ?WorkspaceStatus $requireStatus = null,
    ?WorkspaceStatus $endingStatus = WorkspaceStatus::READY,
): WorkflowData {
    return new WorkflowData(
        require_status: $requireStatus,
        ending_status: $endingStatus,
        sort_order: $sortOrder,
        steps: collect($steps),
    );
}

/**
 * A single shell step of a workflow definition.
 */
function componentStepData(
    string $name = 'Install dependencies',
    string $run = 'composer install',
    WorkflowStepType $type = WorkflowStepType::SHELL,
): WorkflowStepData {
    return new WorkflowStepData(
        name: $name,
        type: $type,
        run: $run,
    );
}

/**
 * A run log, by default a finished successful run of the up workflow.
 *
 * @param  array<int, WorkflowRunLogStepData>  $steps
 */
function componentRunLogData(
    string $id = '20240101T000000Z_repo-feature_up',
    string $name = 'up',
    ?string $parent = null,
    int $timestamp = 1704067200,
    WorkflowStatus $status = WorkflowStatus::SUCCESS,
    ?string $exception = null,
    array $steps = [],
): WorkflowRunLogData {
    return new WorkflowRunLogData(
        id: $id,
        name: $name,
        parent: $parent,
        timestamp: $timestamp,
        status: $status,
        exception: $exception,
        steps: collect($steps),
    );
}

/**
 * A single step of a run log, by default a shell step that succeeded.
 */
function componentRunLogStepData(
    string $name = 'Install dependencies',
    WorkflowStepType $type = WorkflowStepType::SHELL,
    ?int $exitCode = 0,
    string $output = 'done',
    ?string $hash = 'aaa111',
    ?WorkflowStepSkipReason $skipReason = null,
    ?string $run = 'composer install',
    ?string $logId = null,
): WorkflowRunLogStepData {
    return new WorkflowRunLogStepData(
        name: $name,
        type: $type,
        exitCode: $exitCode,
        output: $output,
        skip_reason: $skipReason,
        run: $run,
        hash: $hash,
        log_id: $logId,
    );
}
