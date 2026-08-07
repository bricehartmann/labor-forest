<?php

namespace App\Filament\Pages;

use App\Concerns\Filament\Pages\HasResultNotificationOperations;
use App\Data\ProjectData;
use App\Data\WorkflowRunLogData;
use App\Data\WorkspaceData;
use App\Events\WorkflowFinished;
use App\Events\WorkflowStarted;
use App\Events\WorkflowStepFinished;
use App\Events\WorkflowStepOutputUpdated;
use App\Events\WorkflowStepSkipped;
use App\Events\WorkflowStepStarted;
use App\Services\ProjectsService;
use App\Services\WorkflowService;
use Exception;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

class WorkflowLog extends Page implements HasActions, HasSchemas, HasTable
{
    use HasResultNotificationOperations;
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public ?string $loadedInvalidMessage = null;

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public array $project = [];

    #[Locked]
    public array $workspace = [];

    #[Locked]
    public array $workflowRunLog = [];

    protected string $view = 'filament.pages.workflow-log';

    #[Computed]
    public function projectData(): ProjectData
    {
        return ProjectData::from($this->project);
    }

    #[Computed]
    public function workspaceData(): WorkspaceData
    {
        return WorkspaceData::from($this->workspace);
    }

    #[Computed]
    public function workflowRunLogData(): WorkflowRunLogData
    {
        return WorkflowRunLogData::from($this->workflowRunLog);
    }

    /**
     * The run that started this one, when it was started by another workflow's step.
     *
     * Resolves to null once the parent's log has been deleted, leaving the page with nothing to
     * link to rather than a dead link.
     */
    #[Computed]
    public function parentRunLogData(): ?WorkflowRunLogData
    {
        $parent = $this->workflowRunLogData->parent;

        return $parent === null
            ? null
            : app(WorkflowService::class)->loadWorkflowLogDatum($this->workspaceData, $parent);
    }

    #[On('native:'.WorkflowStarted::class)]
    public function onWorkflowStarted(
        ?string $projectUuid = null,
        ?string $workspaceSlugKebab = null,
        ?string $workflowName = null,
    ): void {
        if ($this->isCurrentRun($projectUuid, $workspaceSlugKebab, $workflowName)) {
            $this->reloadData();
            $this->scrollToStep($this->workflowRunLog['steps'][0]['hash'] ?? null);
        }
    }

    #[On('native:'.WorkflowFinished::class)]
    public function onWorkflowFinished(
        ?string $projectUuid = null,
        ?string $workspaceSlugKebab = null,
        ?string $workflowName = null,
        ?string $status = null,
        ?string $logId = null,
    ): void {
        if ($this->isFinishOfThisRun($projectUuid, $workspaceSlugKebab, $workflowName, $logId)) {
            $this->reloadData();
            $this->scrollToTop();
        }
    }

    #[On('native:'.WorkflowStepStarted::class)]
    public function onWorkflowStepStarted(
        ?string $projectUuid = null,
        ?string $workspaceSlugKebab = null,
        ?string $workflowName = null,
        ?string $stepHash = null,
    ): void {
        if ($this->isCurrentRun($projectUuid, $workspaceSlugKebab, $workflowName)) {
            $this->reloadData();
            $this->scrollToStep($stepHash);
        }
    }

    #[On('native:'.WorkflowStepFinished::class)]
    public function onWorkflowStepFinished(
        ?string $projectUuid = null,
        ?string $workspaceSlugKebab = null,
        ?string $workflowName = null,
        ?string $stepHash = null,
        ?string $status = null,
    ): void {
        if ($this->isCurrentRun($projectUuid, $workspaceSlugKebab, $workflowName)) {
            $this->reloadData();
            $this->scrollToStep($stepHash);
        }
    }

    #[On('native:'.WorkflowStepSkipped::class)]
    public function onWorkflowStepSkipped(
        ?string $projectUuid = null,
        ?string $workspaceSlugKebab = null,
        ?string $workflowName = null,
        ?string $stepHash = null,
        ?string $reason = null,
    ): void {
        if ($this->isCurrentRun($projectUuid, $workspaceSlugKebab, $workflowName)) {
            $this->reloadData();
            $this->scrollToStep($stepHash);
        }
    }

    /**
     * Streamed step output is held in memory by the run job and never written to the log file,
     * so it is patched in from the event payload rather than re-read from disk.
     */
    #[On('native:'.WorkflowStepOutputUpdated::class)]
    public function onWorkflowStepOutputUpdated(
        ?string $projectUuid = null,
        ?string $workspaceSlugKebab = null,
        ?string $workflowName = null,
        ?string $stepHash = null,
        ?string $output = null,
    ): void {
        if (! $this->isCurrentRun($projectUuid, $workspaceSlugKebab, $workflowName)) {
            return;
        }

        foreach ($this->workflowRunLog['steps'] ?? [] as $index => $step) {
            if (($step['hash'] ?? null) === $stepHash) {
                $this->workflowRunLog['steps'][$index]['output'] = (string) $output;

                unset($this->workflowRunLogData);

                $this->scrollToStep($stepHash);

                break;
            }
        }
    }

    /**
     * Ask the page to bring a step into view. Repeated targets are ignored browser side, so a
     * running step streaming output does not fight a reader who has scrolled away.
     */
    private function scrollToStep(?string $stepHash): void
    {
        $this->dispatch('scroll-to-step', stepHash: $stepHash);
    }

    /**
     * Bring the page back to the top once a run ends, superseding any step scroll still animating
     * from the final WorkflowStepFinished broadcast.
     */
    private function scrollToTop(): void
    {
        $this->dispatch('scroll-to-top');
    }

    /**
     * A broadcast is matched to this page by project, workspace and workflow name.
     */
    private function matchesRun(?string $projectUuid, ?string $workspaceSlugKebab, ?string $workflowName): bool
    {
        if ($this->project === [] || $this->workspace === [] || $this->workflowRunLog === []) {
            return false;
        }

        return $projectUuid === $this->projectData->uuid
            && $workspaceSlugKebab === $this->workspaceData->slugKebab()
            && $workflowName === $this->workflowRunLogData->name;
    }

    /**
     * Step events carry no run log id, and step hashes are stable across runs, so a later run of
     * the same workflow is kept away from a finished log by only accepting them while this run is
     * still unfinished.
     */
    private function isCurrentRun(?string $projectUuid, ?string $workspaceSlugKebab, ?string $workflowName): bool
    {
        return $this->matchesRun($projectUuid, $workspaceSlugKebab, $workflowName)
            && $this->workflowRunLogData->status->isLocked();
    }

    /**
     * The finish event races the final step event, which can already have reloaded this page with
     * the finished status, so identity is settled by run log id rather than by the lock state.
     */
    private function isFinishOfThisRun(?string $projectUuid, ?string $workspaceSlugKebab, ?string $workflowName, ?string $logId): bool
    {
        if (! $this->matchesRun($projectUuid, $workspaceSlugKebab, $workflowName)) {
            return false;
        }

        return $logId === null
            ? $this->workflowRunLogData->status->isLocked()
            : $logId === $this->workflowRunLogData->id;
    }

    public function mount(string $uuid, string $slug, string $id): void
    {
        $this->loadProjectData($uuid, $slug, $id);
    }

    protected function reloadData(): void
    {
        $this->loadProjectData($this->projectData->uuid, $this->workspaceData->slugKebab(), $this->workflowRunLogData->id);
    }

    protected function loadProjectData(string $uuid, string $slug, string $id): void
    {
        unset($this->projectData);
        unset($this->workspaceData);
        unset($this->workflowRunLogData);

        $projectService = app(ProjectsService::class);

        try {
            $this->project = $projectService->loadProject($uuid)->toArray();

            $workspaceData = $projectService
                ->loadProjectWorkspaces($this->projectData->path)
                ->first(fn (WorkspaceData $workspaceData) => $workspaceData->slugKebab() === $slug);

            if ($workspaceData === null) {
                $this->redirect(Project::getUrl(['uuid' => $uuid]));

                return;
            }

            $this->workspace = $workspaceData->toArray();

            $workflowRunLogData = app(WorkflowService::class)->loadWorkflowLogDatum($workspaceData, $id);

            if ($workflowRunLogData === null) {
                $this->redirect(Project::getUrl(['uuid' => $uuid]));

                return;
            }

            $this->workflowRunLog = $workflowRunLogData->toArray();
        } catch (Exception $e) {
            $this->loadedInvalidMessage = $e->getMessage();
        }
    }

    public static function getSlug($panel = null): string
    {
        return '/projects/{uuid}/workspaces/{slug}/logs/{id}';
    }

    public function getHeading(): string
    {
        if ($this->workflowRunLog === []) {
            return 'Workflow Log';
        }

        return $this->workflowRunLogData->id;
    }

    public function getSubheading(): ?string
    {
        if ($this->project === [] || $this->workspace === []) {
            return null;
        }

        return $this->projectData->dirName().'-'.$this->workspaceData->slugKebab();
    }
}
