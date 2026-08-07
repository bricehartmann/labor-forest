<?php

namespace App\Filament\Pages;

use App\Concerns\Filament\Pages\HasResultNotificationOperations;
use App\Data\ProjectData;
use App\Data\WorkflowRunLogData;
use App\Data\WorkspaceData;
use App\Enums\Directory;
use App\Enums\FileExtension;
use App\Enums\WorkflowStatus;
use App\Events\WorkflowFinished;
use App\Events\WorkflowStarted;
use App\Services\ProjectsService;
use App\Services\WorkflowService;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Native\Desktop\Facades\System;
use Throwable;

class ProjectWorkflows extends Page implements HasActions, HasSchemas, HasTable
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
    public string $timezone = 'UTC';

    protected string $view = 'filament.pages.project-workflows';

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
    public function workflowLogData(): Collection
    {
        return app(WorkflowService::class)->loadWorkflowLogData($this->workspaceData);
    }

    #[On('native:'.WorkflowFinished::class)]
    #[On('native:'.WorkflowStarted::class)]
    public function onWorkflowStartedOrFinished(
        ?string $projectUuid = null,
        ?string $workspaceSlugKebab = null,
    ): void {
        if ($this->project === [] || $this->workspace === []) {
            return;
        }

        if (
            $projectUuid === $this->projectData->uuid
            && $workspaceSlugKebab === $this->workspaceData->slugKebab()
        ) {
            $this->reloadData();
        }
    }

    public function mount(string $uuid, string $slug): void
    {
        $this->timezone = System::timezone();

        $this->loadProjectData($uuid, $slug);
    }

    protected function reloadData(): void
    {
        $this->loadProjectData($this->projectData->uuid, $this->workspaceData->slugKebab());
        $this->resetTable();
    }

    protected function loadProjectData(string $uuid, string $slug): void
    {
        unset($this->projectData);
        unset($this->workspaceData);
        unset($this->workflowLogData);

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
        } catch (Exception $e) {
            $this->loadedInvalidMessage = $e->getMessage();
        }
    }

    /**
     * @return array<string, array<string, int>> workspace path => (workflow name => sort order)
     */
    protected function loadWorkspaceWorkflowLogData(): array
    {
        $workflowService = app(WorkflowService::class);

        return $workflowService->loadWorkflowLogData($this->workspaceData)->all();
    }

    public static function getSlug($panel = null): string
    {
        return '/projects/{uuid}/workspaces/{slug}';
    }

    public function getHeading(): string
    {
        if ($this->workspace === []) {
            return 'Workflow Logs';
        }

        return $this->workspaceData->branch;
    }

    public function getSubheading(): ?string
    {
        if ($this->project === []) {
            return null;
        }

        return $this->projectData->dirName();
    }

    public function table(Table $table): Table
    {
        if ($this->workspace === []) {
            return $table->records(fn () => []);
        }

        return $table
            ->records(fn () => $this->workflowLogData->toArray())
            ->columns([
                TextColumn::make('timestamp')
                    ->formatStateUsing(fn ($state) => Carbon::createFromTimestampUTC($state)->tz($this->timezone)->format('Y-m-d H:i:s T'))
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Workflow')
                    ->formatStateUsing(fn ($state) => ucwords($state)),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => WorkflowStatus::from($state)->getColor())
                    ->size(TextSize::Large),
            ])
            ->selectable()
            ->checkIfRecordIsSelectableUsing(fn (array $record) => ! WorkflowStatus::from($record['status'])->isLocked())
            ->filters([
                // ...
            ])
            ->recordActions([
                Action::make('view')
                    ->button()
                    ->icon(Heroicon::Eye)
                    ->label('View')
                    ->color('primary')
                    ->url(fn (array $record) => WorkflowLog::getUrl(['uuid' => $this->projectData->uuid, 'slug' => $this->workspaceData->slugKebab(), 'id' => $record['id']])),
            ])
            ->toolbarActions([
                BulkAction::make('delete')
                    ->button()
                    ->label('Delete')
                    ->color('danger')
                    ->action(function (Collection $records) {
                        static::resultNotificationOperation(
                            callback: function () use ($records) {
                                $pathPrefix = implode(DIRECTORY_SEPARATOR, [
                                    $this->workspaceData->path,
                                    Directory::BASE->value,
                                    Directory::IGNORED->value,
                                    Directory::LOGS->value,
                                ]);
                                $paths = $records->map(fn (array $record) => $pathPrefix.'/'.WorkflowRunLogData::from($record)->id.'.'.FileExtension::YAML->value
                                )->toArray();

                                if (! File::delete($paths)) {
                                    throw new Exception('Failed to delete workflow log data.');
                                }

                                $this->reloadData();
                            },
                            successTitle: 'Log records deleted',
                            failureBody: fn (Throwable $th) => $th->getMessage(),
                        );
                    }),
            ])
            ->emptyStateHeading('No log data')
            ->defaultSort('timestamp', 'desc')
            ->paginated();
    }
}
