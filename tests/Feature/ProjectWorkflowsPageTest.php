<?php

use App\Enums\WorkflowStatus;
use App\Events\WorkflowFinished;
use App\Events\WorkflowStarted;
use App\Exceptions\ProjectNotFound;
use App\Filament\Pages\Project;
use App\Filament\Pages\ProjectWorkflows;
use App\Filament\Pages\WorkflowLog;
use App\Services\ProjectsService;
use App\Services\WorkflowService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery\MockInterface;
use Native\Desktop\Facades\System;

beforeEach(function () {
    Storage::fake('user_home');

    $this->uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $this->projectPath = '/tmp/repo';
    $this->workspacePath = '/tmp/repo-feature';
    $this->slug = 'repo-feature';
    $this->logId = '20240101T000000Z_repo-feature_up';

    $this->timezone = 'UTC';
    $this->projectLoadException = null;
    $this->loadProjectCalls = 0;
    $this->loadWorkflowLogDataCalls = 0;
    $this->workspaces = collect([componentWorkspaceData($this->workspacePath)]);
    $this->runLogs = collect([componentRunLogData(id: $this->logId)]);

    System::shouldReceive('timezone')->andReturnUsing(fn () => $this->timezone);

    $this->mock(ProjectsService::class, function (MockInterface $mock) {
        $mock->shouldReceive('loadProject')->andReturnUsing(function () {
            $this->loadProjectCalls++;

            if ($this->projectLoadException !== null) {
                throw $this->projectLoadException;
            }

            return componentProjectData($this->uuid, $this->projectPath);
        });

        $mock->shouldReceive('loadProjectWorkspaces')->andReturnUsing(fn () => $this->workspaces);
    });

    $this->mock(WorkflowService::class, function (MockInterface $mock) {
        $mock->shouldReceive('loadWorkflowLogData')->andReturnUsing(function () {
            $this->loadWorkflowLogDataCalls++;

            return $this->runLogs;
        });
    });
});

describe('mount', function () {
    it('lists the run logs of the workspace matching the slug', function () {
        $component = Livewire::test(ProjectWorkflows::class, ['uuid' => $this->uuid, 'slug' => $this->slug])
            ->assertOk()
            ->assertSet('loadedInvalidMessage', null)
            ->assertSee('feature')
            ->assertSee('repo')
            ->assertSee('Up')
            ->assertSee('2024-01-01 00:00:00 UTC');

        expect($component->instance()->getTableRecords())->toHaveCount(1);
    });

    it('renders the run timestamps in the system timezone', function () {
        $this->timezone = 'America/New_York';

        Livewire::test(ProjectWorkflows::class, ['uuid' => $this->uuid, 'slug' => $this->slug])
            ->assertOk()
            ->assertSet('timezone', 'America/New_York')
            ->assertSee('2023-12-31 19:00:00 EST');
    });

    it('names the run that started a chained run', function () {
        $this->runLogs = collect([
            componentRunLogData(id: 'parent-log', name: 'deploy'),
            componentRunLogData(id: 'child-log', name: 'down', parent: 'parent-log'),
        ]);

        Livewire::test(ProjectWorkflows::class, ['uuid' => $this->uuid, 'slug' => $this->slug])
            ->assertOk()
            ->assertTableColumnFormattedStateSet('parent', 'Deploy', '1');
    });

    it('records the load failure instead of throwing', function () {
        $this->projectLoadException = new ProjectNotFound($this->uuid);

        Livewire::test(ProjectWorkflows::class, ['uuid' => $this->uuid, 'slug' => $this->slug])
            ->assertOk()
            ->assertSet('loadedInvalidMessage', "Project with UUID '{$this->uuid}' not found.")
            ->assertSee('Workflow Logs')
            ->assertSee("Project with UUID '{$this->uuid}' not found.")
            ->assertDontSee('2024-01-01 00:00:00 UTC');
    });

    it('redirects to the project page when no workspace matches the slug', function () {
        $this->workspaces = collect([componentWorkspaceData('/tmp/repo-other', branch: 'other')]);

        Livewire::test(ProjectWorkflows::class, ['uuid' => $this->uuid, 'slug' => $this->slug])
            ->assertRedirect(Project::getUrl(['uuid' => $this->uuid]));

        expect($this->loadWorkflowLogDataCalls)->toBe(0);
    });
});

describe('view record action', function () {
    it('links a row to the log page of that run', function () {
        Livewire::test(ProjectWorkflows::class, ['uuid' => $this->uuid, 'slug' => $this->slug])
            ->assertOk()
            ->assertActionHasUrl(
                TestAction::make('view')->table('0'),
                WorkflowLog::getUrl(['uuid' => $this->uuid, 'slug' => $this->slug, 'id' => $this->logId]),
            );
    });

    it('offers no row to view when the project could not be loaded', function () {
        $this->projectLoadException = new ProjectNotFound($this->uuid);

        $component = Livewire::test(ProjectWorkflows::class, ['uuid' => $this->uuid, 'slug' => $this->slug])
            ->assertOk()
            ->assertActionDoesNotExist(TestAction::make('view')->table());

        expect($component->instance()->getTableRecords())->toHaveCount(0);
    });
});

describe('delete bulk action', function () {
    it('deletes the log file of every selected run and reloads', function () {
        $component = Livewire::test(ProjectWorkflows::class, ['uuid' => $this->uuid, 'slug' => $this->slug])
            ->assertOk();

        expect($this->loadProjectCalls)->toBe(1);

        File::partialMock()
            ->shouldReceive('delete')
            ->once()
            ->with([projectWorkflowsPageLogPath($this->workspacePath, $this->logId)])
            ->andReturn(true);

        $component
            ->set('selectedTableRecords', ['0'])
            ->callAction(TestAction::make('delete')->table()->bulk())
            ->assertNotified('Log records deleted');

        expect($this->loadProjectCalls)->toBe(2);
    });

    it('reports a failed delete and does not reload', function () {
        $component = Livewire::test(ProjectWorkflows::class, ['uuid' => $this->uuid, 'slug' => $this->slug])
            ->assertOk();

        File::partialMock()
            ->shouldReceive('delete')
            ->once()
            ->andReturn(false);

        $component
            ->set('selectedTableRecords', ['0'])
            ->callAction(TestAction::make('delete')->table()->bulk())
            ->assertNotified('Whoops! Something went wrong.');

        expect($this->loadProjectCalls)->toBe(1);
    });
});

describe('record selectability', function () {
    it('allows a finished run to be selected', function () {
        $table = Livewire::test(ProjectWorkflows::class, ['uuid' => $this->uuid, 'slug' => $this->slug])
            ->assertOk()
            ->instance()
            ->getTable();

        expect($table->isRecordSelectable(['status' => WorkflowStatus::SUCCESS->value]))->toBeTrue()
            ->and($table->isRecordSelectable(['status' => WorkflowStatus::FAILED->value]))->toBeTrue();
    });

    it('excludes a pending or running run from selection', function () {
        $table = Livewire::test(ProjectWorkflows::class, ['uuid' => $this->uuid, 'slug' => $this->slug])
            ->assertOk()
            ->instance()
            ->getTable();

        expect($table->isRecordSelectable(['status' => WorkflowStatus::PENDING->value]))->toBeFalse()
            ->and($table->isRecordSelectable(['status' => WorkflowStatus::RUNNING->value]))->toBeFalse();
    });
});

describe('onWorkflowStartedOrFinished', function () {
    it('reloads when a workflow starts in this workspace', function () {
        Livewire::test(ProjectWorkflows::class, ['uuid' => $this->uuid, 'slug' => $this->slug])
            ->assertOk()
            ->dispatch(
                'native:'.WorkflowStarted::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
            )
            ->assertOk();

        expect($this->loadProjectCalls)->toBe(2);
    });

    it('reloads when a workflow finishes in this workspace', function () {
        Livewire::test(ProjectWorkflows::class, ['uuid' => $this->uuid, 'slug' => $this->slug])
            ->assertOk()
            ->dispatch(
                'native:'.WorkflowFinished::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
            )
            ->assertOk();

        expect($this->loadProjectCalls)->toBe(2);
    });

    it('ignores a workflow of another project', function () {
        Livewire::test(ProjectWorkflows::class, ['uuid' => $this->uuid, 'slug' => $this->slug])
            ->assertOk()
            ->dispatch(
                'native:'.WorkflowStarted::class,
                projectUuid: 'ffffffff-ffff-ffff-ffff-ffffffffffff',
                workspaceSlugKebab: $this->slug,
            )
            ->assertOk();

        expect($this->loadProjectCalls)->toBe(1);
    });

    it('ignores a workflow of another workspace', function () {
        Livewire::test(ProjectWorkflows::class, ['uuid' => $this->uuid, 'slug' => $this->slug])
            ->assertOk()
            ->dispatch(
                'native:'.WorkflowStarted::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: 'repo-other',
            )
            ->assertOk();

        expect($this->loadProjectCalls)->toBe(1);
    });

    it('ignores the event entirely when the page never loaded', function () {
        $this->projectLoadException = new ProjectNotFound($this->uuid);

        Livewire::test(ProjectWorkflows::class, ['uuid' => $this->uuid, 'slug' => $this->slug])
            ->assertOk()
            ->dispatch(
                'native:'.WorkflowStarted::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
            )
            ->assertOk();

        expect($this->loadProjectCalls)->toBe(1);
    });
});

/**
 * The absolute path the delete bulk action is expected to hand to the File facade for a run log.
 */
function projectWorkflowsPageLogPath(string $workspacePath, string $logId): string
{
    return implode(DIRECTORY_SEPARATOR, [$workspacePath, '.laborforest', 'ignored', 'logs']).'/'.$logId.'.yaml';
}
