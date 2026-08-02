<?php

namespace App\Filament\Pages;

use App\Concerns\Filament\Pages\HasResultNotificationOperations;
use App\Data\ProjectData;
use App\Enums\WorkspaceStatus;
use App\Exceptions\InvalidProjectsFile;
use App\Services\ProjectsService;
use Exception;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

class Project extends Page implements HasActions, HasSchemas, HasTable
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
    public array $workspaces = [];

    protected string $view = 'filament.pages.project';

    #[Computed]
    public function projectData(): ProjectData
    {
        return ProjectData::from($this->project);
    }

    public function mount(string $uuid): void
    {
        $projectService = app(ProjectsService::class);

        try {
            $this->project = $projectService->loadProject($uuid)->toArray();
            $this->workspaces = $projectService->loadProjectWorkspaces($this->projectData->path)->toArray();
        } catch (InvalidProjectsFile $e) {
            $this->loadedInvalidMessage = $e->messagesAsString();
        } catch (Exception $e) {
            $this->loadedInvalidMessage = $e->getMessage();
        }
    }

    /**
     * @return string|null
     */
    public static function getSlug($panel = null): string
    {
        return '/projects/{uuid}';
    }

    public function getHeading(): string
    {
        return $this->projectData->title();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => $this->workspaces)
            ->columns([
                TextColumn::make('branch'),
                TextColumn::make('status')
                    ->badge()
                    ->size(TextSize::Large)
                    ->color(fn ($state) => WorkspaceStatus::from($state)->getColor()),
            ])
            ->filters([
                // ...
            ])
            ->recordActions([
                // ...
            ])
            ->toolbarActions([
                // ...
            ]);
    }
}
