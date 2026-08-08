<?php

use App\Enums\WorkflowStatus;
use App\Events\WorkflowFinished;
use App\Exceptions\ProjectNotFound;
use App\Livewire\WorkflowNotifications;
use App\Services\ProjectsService;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery\MockInterface;

beforeEach(function () {
    Storage::fake('user_home');

    $this->uuid = '11111111-1111-1111-1111-111111111111';
    $this->slug = 'repo-feature';
    $this->logId = 'log-1';
    $this->logUrl = '/projects/'.$this->uuid.'/workspaces/repo-feature/logs/log-1';
});

describe('onWorkflowFinished', function () {
    beforeEach(function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProject')
                ->with($this->uuid)
                ->andReturn(componentProjectData($this->uuid));
        });
    });

    it('reports a successful run with a link to its log', function () {
        Livewire::test(WorkflowNotifications::class)
            ->dispatch(
                'native:'.WorkflowFinished::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
                workflowName: 'up',
                status: WorkflowStatus::SUCCESS->value,
                logId: $this->logId,
            );

        $notifications = workflowNotificationsSent();

        expect($notifications)->toHaveCount(1)
            ->and($notifications[0]['title'])->toBe('Workflow succeeded')
            ->and($notifications[0]['status'])->toBe('success')
            ->and($notifications[0]['actions'])->toHaveCount(1)
            ->and($notifications[0]['actions'][0]['name'])->toBe('view')
            ->and($notifications[0]['actions'][0]['label'])->toBe('View log')
            ->and($notifications[0]['actions'][0]['url'])->toBe($this->logUrl);

        Livewire::test(WorkflowNotifications::class)->assertNotified('Workflow succeeded');
    });

    it('reports a failed run', function () {
        Livewire::test(WorkflowNotifications::class)
            ->dispatch(
                'native:'.WorkflowFinished::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
                workflowName: 'up',
                status: WorkflowStatus::FAILED->value,
                logId: $this->logId,
            );

        $notifications = workflowNotificationsSent();

        expect($notifications)->toHaveCount(1)
            ->and($notifications[0]['status'])->toBe('danger');

        Livewire::test(WorkflowNotifications::class)->assertNotified('Workflow failed');
    });

    it('names the project directory in the body when the project can be loaded', function () {
        Livewire::test(WorkflowNotifications::class)
            ->dispatch(
                'native:'.WorkflowFinished::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
                workflowName: 'up',
                status: WorkflowStatus::SUCCESS->value,
                logId: $this->logId,
            );

        expect(workflowNotificationsSent()[0]['body'])->toBe('Up — repo-repo-feature');
    });

    it('still notifies without a log link when the run carries no log id', function () {
        Livewire::test(WorkflowNotifications::class)
            ->dispatch(
                'native:'.WorkflowFinished::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
                workflowName: 'up',
                status: WorkflowStatus::SUCCESS->value,
                logId: null,
            );

        expect(workflowNotificationsSent()[0]['actions'])->toBe([]);

        Livewire::test(WorkflowNotifications::class)->assertNotified('Workflow succeeded');
    });
});

describe('onWorkflowFinished body when the project is gone', function () {
    it('drops the project name rather than failing', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProject')
                ->with($this->uuid)
                ->andThrow(new ProjectNotFound($this->uuid));
        });

        Livewire::test(WorkflowNotifications::class)
            ->dispatch(
                'native:'.WorkflowFinished::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
                workflowName: 'up',
                status: WorkflowStatus::SUCCESS->value,
                logId: $this->logId,
            );

        expect(workflowNotificationsSent()[0]['body'])->toBe('Up — repo-feature');
    });
});

describe('onWorkflowFinished guard clauses', function () {
    beforeEach(function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProject')->never();
        });
    });

    it('ignores a broadcast without a status', function () {
        Livewire::test(WorkflowNotifications::class)
            ->dispatch(
                'native:'.WorkflowFinished::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
                workflowName: 'up',
                status: null,
                logId: $this->logId,
            )
            ->assertNotNotified();
    });

    it('ignores a status it does not recognise', function () {
        Livewire::test(WorkflowNotifications::class)
            ->dispatch(
                'native:'.WorkflowFinished::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
                workflowName: 'up',
                status: 'exploded',
                logId: $this->logId,
            )
            ->assertNotNotified();
    });

    it('ignores a broadcast without a project uuid', function () {
        Livewire::test(WorkflowNotifications::class)
            ->dispatch(
                'native:'.WorkflowFinished::class,
                projectUuid: null,
                workspaceSlugKebab: $this->slug,
                workflowName: 'up',
                status: WorkflowStatus::SUCCESS->value,
                logId: $this->logId,
            )
            ->assertNotNotified();
    });

    it('ignores a broadcast without a workspace slug', function () {
        Livewire::test(WorkflowNotifications::class)
            ->dispatch(
                'native:'.WorkflowFinished::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: null,
                workflowName: 'up',
                status: WorkflowStatus::SUCCESS->value,
                logId: $this->logId,
            )
            ->assertNotNotified();
    });

    it('ignores a broadcast without a workflow name', function () {
        Livewire::test(WorkflowNotifications::class)
            ->dispatch(
                'native:'.WorkflowFinished::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
                workflowName: null,
                status: WorkflowStatus::SUCCESS->value,
                logId: $this->logId,
            )
            ->assertNotNotified();
    });

    it('ignores a run that is only pending', function () {
        Livewire::test(WorkflowNotifications::class)
            ->dispatch(
                'native:'.WorkflowFinished::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
                workflowName: 'up',
                status: WorkflowStatus::PENDING->value,
                logId: $this->logId,
            )
            ->assertNotNotified();
    });

    it('ignores a run that is still running', function () {
        Livewire::test(WorkflowNotifications::class)
            ->dispatch(
                'native:'.WorkflowFinished::class,
                projectUuid: $this->uuid,
                workspaceSlugKebab: $this->slug,
                workflowName: 'up',
                status: WorkflowStatus::RUNNING->value,
                logId: $this->logId,
            )
            ->assertNotNotified();
    });
});

describe('suppression while viewing the log', function () {
    it('says nothing when the finished run is the log page already on screen', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProject')->never();
        });

        Livewire::test(WorkflowNotificationsViewingLog::class)
            ->dispatch(
                'native:'.WorkflowFinished::class,
                projectUuid: WorkflowNotificationsViewingLog::UUID,
                workspaceSlugKebab: 'repo-feature',
                workflowName: 'up',
                status: WorkflowStatus::SUCCESS->value,
                logId: 'log-1',
            )
            ->assertNotNotified();
    });

    it('still reports a different run that finishes while a log is on screen', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProject')
                ->with(WorkflowNotificationsViewingLog::UUID)
                ->andReturn(componentProjectData(WorkflowNotificationsViewingLog::UUID));
        });

        Livewire::test(WorkflowNotificationsViewingLog::class)
            ->dispatch(
                'native:'.WorkflowFinished::class,
                projectUuid: WorkflowNotificationsViewingLog::UUID,
                workspaceSlugKebab: 'repo-feature',
                workflowName: 'up',
                status: WorkflowStatus::SUCCESS->value,
                logId: 'log-2',
            )
            ->assertNotified('Workflow succeeded');
    });
});

/**
 * The notification payloads sent so far, read out of the session without consuming them the way
 * assertNotified() does.
 *
 * @return array<int, array<string, mixed>>
 */
function workflowNotificationsSent(): array
{
    return session()->get('filament.claimed_notifications')
        ?? session()->get('filament.notifications')
        ?? [];
}

/**
 * A WorkflowNotifications already parked on the log page of the run that finishes, standing in for
 * the request path that mount() cannot derive under Livewire::test().
 */
class WorkflowNotificationsViewingLog extends WorkflowNotifications
{
    public const UUID = '11111111-1111-1111-1111-111111111111';

    public function mount(): void
    {
        $this->mountedPath = 'projects/'.self::UUID.'/workspaces/repo-feature/logs/log-1';
    }
}
