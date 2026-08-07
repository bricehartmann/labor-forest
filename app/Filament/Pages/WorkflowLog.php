<?php

namespace App\Filament\Pages;

use App\Concerns\Filament\Pages\HasResultNotificationOperations;
use App\Data\ProjectData;
use App\Data\WorkflowRunLogData;
use App\Data\WorkspaceData;
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

    public function onWorkflowLogUpdate(string $id)
    {
        if ($this->workflowRunLogData->id === $id) {
            $this->reloadData();
        }
    }

    public function mount(string $uuid, string $slug, string $id): void
    {
        $this->loadProjectData($uuid, $slug, $id);
    }

    protected function reloadData(): void
    {
        $this->loadProjectData($this->projectData->uuid, $this->workspaceData->slugKebab(), $this->workflowRunLogData->id);
        $this->resetTable();
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
