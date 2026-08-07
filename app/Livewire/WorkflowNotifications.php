<?php

namespace App\Livewire;

use App\Enums\WorkflowStatus;
use App\Events\WorkflowFinished;
use App\Filament\Pages\WorkflowLog;
use App\Services\ProjectsService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Rendered on every panel page through a render hook so a run that finishes while the user is
 * somewhere else still reports itself.
 */
class WorkflowNotifications extends Component
{
    #[Locked]
    public string $mountedPath = '';

    public function mount(): void
    {
        $this->mountedPath = request()->path();
    }

    #[On('native:'.WorkflowFinished::class)]
    public function onWorkflowFinished(
        ?string $projectUuid = null,
        ?string $workspaceSlugKebab = null,
        ?string $workflowName = null,
        ?string $status = null,
        ?string $logId = null,
    ): void {
        $workflowStatus = $status === null ? null : WorkflowStatus::tryFrom($status);

        if ($workflowStatus === null || $projectUuid === null || $workspaceSlugKebab === null || $workflowName === null) {
            return;
        }

        $logUrl = $logId === null
            ? null
            : WorkflowLog::getUrl(['uuid' => $projectUuid, 'slug' => $workspaceSlugKebab, 'id' => $logId], isAbsolute: false);

        if ($this->isViewing($logUrl)) {
            return;
        }

        $isSuccess = $workflowStatus === WorkflowStatus::SUCCESS;

        Notification::make()
            ->when(
                value: $isSuccess,
                callback: fn (Notification $notification) => $notification->success(),
                default: fn (Notification $notification) => $notification->danger(),
            )
            ->title($isSuccess ? 'Workflow succeeded' : 'Workflow failed')
            ->body($this->buildBody($projectUuid, $workspaceSlugKebab, $workflowName))
            ->icon($workflowStatus->getIcon())
            ->actions($logUrl === null ? [] : [
                Action::make('view')
                    ->label('View log')
                    ->button()
                    ->url($logUrl)
                    ->close(),
            ])
            ->send();
    }

    /**
     * The log page reloads and scrolls itself when its own run finishes, so a toast there would
     * only repeat what the page already shows.
     */
    private function isViewing(?string $url): bool
    {
        if ($url === null) {
            return false;
        }

        return ltrim($url, '/') === ltrim($this->mountedPath, '/');
    }

    /**
     * The broadcast carries a project uuid, which means nothing to a reader, so the project is
     * named where it can still be loaded and dropped from the body where it cannot.
     */
    private function buildBody(string $projectUuid, string $workspaceSlugKebab, string $workflowName): string
    {
        $projectDirName = rescue(fn (): ?string => app(ProjectsService::class)->loadProject($projectUuid)->dirName(), null, report: false);

        return $projectDirName === null
            ? $workflowName.' — '.$workspaceSlugKebab
            : $workflowName.' — '.$projectDirName.'-'.$workspaceSlugKebab;
    }

    public function render(): View
    {
        return view('livewire.workflow-notifications');
    }
}
