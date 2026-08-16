<?php

namespace App\Filament\Widgets;

use App\Concerns\Filament\Pages\HasResultNotificationOperations;
use App\Services\GitHubService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Process;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

class AppVersionWidget extends Widget implements HasActions, HasSchemas
{
    use HasResultNotificationOperations;
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected static ?int $sort = 1;

    protected string $view = 'filament.widgets.app-version-widget';

    #[Locked]
    public ?string $latestReleaseTag = null;

    #[Locked]
    public ?string $latestReleaseHtmlUrl = null;

    public function mount(): void
    {
        $releaseData = rescue(fn () => app(GitHubService::class)->getLatestReleaseData(), report: false);

        if ($releaseData) {
            $this->latestReleaseTag = $releaseData->tag_name;
            $this->latestReleaseHtmlUrl = $releaseData->html_url;
        }
    }

    /**
     * Whether the installed version is the newest published release.
     *
     * The null check is load-bearing: a failed lookup leaves the tag null, and an unpackaged run has
     * no configured version, so a bare comparison would answer "latest" having learned nothing.
     */
    #[Computed]
    public function isLatestVersion(): bool
    {
        return $this->latestReleaseTag !== null
            && $this->latestReleaseTag === config('nativephp.version');
    }

    public function upgradeAction(): Action
    {
        return Action::make('upgrade')
            ->label(fn () => 'Upgrade to '.$this->latestReleaseTag)
            ->color('warning')
            ->action(function () {
                Process::run(['open', $this->latestReleaseHtmlUrl]);
            });
    }

    protected function getViewData(): array
    {
        return ['appVersion' => config('nativephp.version')];
    }
}
