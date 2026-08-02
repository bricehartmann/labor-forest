<?php

namespace App\Filament\Pages;

use App\Concerns\Filament\Pages\HasResultNotificationOperations;
use App\Exceptions\InvalidProjectsFile;
use App\Exceptions\ProjectNotFound;
use App\Services\ProjectsService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;

class Project extends Page
{
    use HasResultNotificationOperations;

    public ?string $loadedInvalidMessage = null;

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public array $project = [];

    protected string $view = 'filament.pages.project';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::RocketLaunch;

    public function mount(string $uuid): void
    {
        try {
            $this->project = app(ProjectsService::class)->loadProject($uuid)->toArray();
        } catch (InvalidProjectsFile $e) {
            $this->loadedInvalidMessage = $e->messagesAsString();
        } catch (ProjectNotFound $e) {
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
}
