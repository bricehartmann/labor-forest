<?php

use App\Exceptions\InvalidProjectsFile;
use App\Exceptions\InvalidSettingsFile;
use App\Providers\Filament\AppPanelProvider;
use App\Services\ProjectsService;
use App\Services\SettingsService;
use Filament\Panel;
use Mockery\MockInterface;

it('builds a navigation item per project', function () {
    $this->mock(ProjectsService::class, function (MockInterface $mock) {
        $mock->shouldReceive('loadProjects')->once()->andReturn(collect([
            componentProjectData('11111111-1111-1111-1111-111111111111', '/tmp/repo'),
        ]));
    });

    $panel = (new AppPanelProvider(app()))->panel(Panel::make());

    expect($panel->getNavigationItems())->toHaveCount(1)
        ->and($panel->getNavigationItems()[0]->getLabel())->toBe('repo')
        ->and($panel->getNavigationItems()[0]->getUrl())
        ->toBe('/projects/11111111-1111-1111-1111-111111111111');
});

it('builds the panel from defaults when the state files cannot be read', function () {
    $this->mock(ProjectsService::class, function (MockInterface $mock) {
        $mock->shouldReceive('loadProjects')->once()
            ->andThrow(new InvalidProjectsFile('.laborforest/projects.yaml', ['broken']));
    });

    $this->mock(SettingsService::class, function (MockInterface $mock) {
        $mock->shouldReceive('loadSettings')->once()
            ->andThrow(new InvalidSettingsFile('.laborforest/settings.yaml', ['broken']));
    });

    $panel = (new AppPanelProvider(app()))->panel(Panel::make());

    // no projects to navigate to, and the dark mode SettingsData::defaults() carries
    expect($panel->getNavigationItems())->toBe([])
        ->and($panel->hasDarkModeForced())->toBeTrue();
});
