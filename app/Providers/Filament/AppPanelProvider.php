<?php

namespace App\Providers\Filament;

use App\Data\ProjectData;
use App\Data\SettingsData;
use App\Services\ProjectsService;
use App\Services\SettingsService;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $projects = rescue(fn () => app(ProjectsService::class)->loadProjects(), collect());
        $settings = rescue(fn () => app(SettingsService::class)->loadSettings(), new SettingsData);

        return $panel
            ->default()
            ->id('app')
            ->brandName('🌲 LaborForest')
            ->path('')
            ->viteTheme('resources/css/filament/app/theme.css')
            ->when(
                value: $settings->dark_mode,
                callback: fn (Panel $p) => $p->darkMode(isForced: true),
                default: fn (Panel $p) => $p->darkMode(false),
            )
            ->colors([
                'primary' => Color::hex('#9d4edd'),
                'danger' => Color::hex('#bb2124'),
                'success' => Color::hex('#22bb33'),
                'warning' => Color::hex('#f0ad4e'),
                'info' => Color::hex('#5bc0de'),
                'gray' => Color::hex('#ffffff'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->navigationGroups([
                NavigationGroup::make()
                    ->label(\App\Enums\NavigationGroup::PROJECTS->value)
                    ->icon(Heroicon::RocketLaunch)
                    ->collapsible(false),
            ])
            ->navigationItems(
                $projects->map(fn (ProjectData $project) => NavigationItem::make($project->dirName())
                    ->group(\App\Enums\NavigationGroup::PROJECTS->value)
                    ->url('/projects/'.$project->uuid)
                    ->isActiveWhen(fn () => str(request()->path())->endsWith($project->uuid))
                )->all()
            )
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ]);
    }
}
