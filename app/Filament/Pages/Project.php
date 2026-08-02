<?php

namespace App\Filament\Pages;

use App\Concerns\Filament\Pages\HasResultNotificationOperations;
use App\Data\ProjectData;
use App\Data\WorkspaceData;
use App\Enums\WorkspaceStatus;
use App\Exceptions\InvalidProjectsFile;
use App\Services\LaunchService;
use App\Services\ProjectsService;
use App\Services\SettingsService;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
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
use Throwable;

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

    public static function getSlug($panel = null): string
    {
        return '/projects/{uuid}';
    }

    public function getHeading(): string
    {
        return $this->projectData->dirName();
    }

    public function table(Table $table): Table
    {
        $settings = app(SettingsService::class)->loadSettings();

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
                ActionGroup::make([
                    Action::make('open_terminal')
                        ->hidden(empty($settings->command_launch_terminal))
                        ->action(function (array $record) {
                            $workspaceData = WorkspaceData::from($record);

                            static::resultNotificationOperation(
                                callback: function () use ($workspaceData) {
                                    app(LaunchService::class)->launchTerminal($this->projectData, $workspaceData);
                                },
                                successTitle: 'Terminal launched',
                                failureBody: fn (Throwable $th) => $th->getMessage(),
                            );
                        }),
                ])
                    ->button()
                    ->label('Open')
                    ->color('info'),
            ])
            ->toolbarActions([
                // ...
            ])
            ->paginated(false);
    }
}
