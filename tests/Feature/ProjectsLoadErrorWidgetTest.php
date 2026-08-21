<?php

use App\Data\ProjectData;
use App\Exceptions\InvalidProjectsFile;
use App\Filament\Widgets\ProjectsLoadErrorWidget;
use App\Services\ProjectsService;
use Livewire\Livewire;
use Mockery\MockInterface;

beforeEach(function () {
    $this->uuid = '11111111-1111-1111-1111-111111111111';
    $this->loadFailure = projectsLoadErrorWidgetFailure();
});

describe('canView', function () {
    it('hides the widget when the projects file loads', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjects')->once()->andReturn(collect([
                projectsLoadErrorWidgetProject($this->uuid),
            ]));
        });

        expect(ProjectsLoadErrorWidget::canView())->toBeFalse();
    });

    it('shows the widget when the projects file cannot be loaded', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjects')->once()->andThrow($this->loadFailure);
        });

        expect(ProjectsLoadErrorWidget::canView())->toBeTrue();
    });
});

describe('mount', function () {
    it('records the load failure message and renders it in the callout', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjects')->once()->andThrow($this->loadFailure);
        });

        Livewire::test(ProjectsLoadErrorWidget::class)
            ->assertOk()
            ->assertSet('loadedInvalidMessage', $this->loadFailure->getMessage())
            ->assertSee('Issue Loading Projects')
            ->assertSee($this->loadFailure->getMessage());
    });

    it('leaves the message empty when the projects file loads', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjects')->once()->andReturn(collect([
                projectsLoadErrorWidgetProject($this->uuid),
            ]));
        });

        Livewire::test(ProjectsLoadErrorWidget::class)
            ->assertOk()
            ->assertSet('loadedInvalidMessage', null);
    });
});

/**
 * The exception ProjectsService throws when projects.yaml holds something other than a list.
 */
function projectsLoadErrorWidgetFailure(string $path = '.laborforest/projects.yaml'): InvalidProjectsFile
{
    return InvalidProjectsFile::notAList($path, 'string');
}

/**
 * A single valid project, so the success path has something to return.
 */
function projectsLoadErrorWidgetProject(string $uuid): ProjectData
{
    return componentProjectData($uuid);
}
