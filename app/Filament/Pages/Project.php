<?php

namespace App\Filament\Pages;

use App\Concerns\Filament\Pages\HasResultNotificationOperations;
use App\Data\ProjectData;
use App\Data\WorktreeData;
use App\Exceptions\InvalidProjectsFile;
use App\Services\GitWorktreeService;
use App\Services\ProjectsService;
use Exception;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

class Project extends Page
{
    use HasResultNotificationOperations;

    public ?string $loadedInvalidMessage = null;

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public array $project = [];

    #[Locked]
    public array $worktrees = [];

    protected string $view = 'filament.pages.project';

    #[Computed]
    public function projectData(): ProjectData
    {
        return ProjectData::from($this->project);
    }

    #[Computed]
    public function worktreeData(): Collection
    {
        return collect(WorktreeData::collect($this->worktrees));
    }

    public function mount(string $uuid): void
    {
        try {
            $this->project = app(ProjectsService::class)->loadProject($uuid)->toArray();
            $this->worktrees = app(GitWorktreeService::class)->listWorktrees($this->projectData->path)->toArray();
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
}
