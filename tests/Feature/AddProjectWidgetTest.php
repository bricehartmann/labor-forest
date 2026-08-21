<?php

use App\Enums\SessionKey;
use App\Exceptions\GitStatusNotClean;
use App\Filament\Widgets\AddProjectWidget;
use App\Services\ProjectsService;
use Livewire\Livewire;
use Mockery\MockInterface;

beforeEach(function () {
    $this->uuid = '11111111-1111-1111-1111-111111111111';
    $this->pickedPath = '/tmp/repo';
});

describe('canView', function () {
    it('shows the widget when the projects file loads', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjects')->once()->andReturn(collect());
        });

        expect(AddProjectWidget::canView())->toBeTrue();
    });

    it('hides the widget when the projects file cannot be loaded', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjects')->once()->andThrow(new Exception('projects.yaml is invalid.'));
        });

        expect(AddProjectWidget::canView())->toBeFalse();
    });
});

describe('addProject action', function () {
    it('adds the picked directory as a project and redirects to it', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjects')->andReturn(collect());
            $mock->shouldReceive('addProject')
                ->once()
                ->with($this->pickedPath)
                ->andReturn(componentProjectData($this->uuid, $this->pickedPath));
        });

        Livewire::test(AddProjectWidgetWithPickedDirectory::class)
            ->assertOk()
            ->callAction('addProject')
            ->assertNotified('Project added')
            ->assertRedirect('/projects/'.$this->uuid);

        expect(session(SessionKey::PROJECT_CREATED->value))->toBe($this->uuid);
    });

    it('does nothing when the directory picker is cancelled', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjects')->andReturn(collect());
            $mock->shouldNotReceive('addProject');
        });

        Livewire::test(AddProjectWidgetWithCancelledPicker::class)
            ->assertOk()
            ->callAction('addProject')
            ->assertNotNotified()
            ->assertNoRedirect();

        expect(session()->has(SessionKey::PROJECT_CREATED->value))->toBeFalse();
    });

    it('reports a failure notification without redirecting when the project cannot be added', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjects')->andReturn(collect());
            $mock->shouldReceive('addProject')
                ->once()
                ->with($this->pickedPath)
                ->andThrow(new GitStatusNotClean($this->pickedPath));
        });

        Livewire::test(AddProjectWidgetWithPickedDirectory::class)
            ->assertOk()
            ->callAction('addProject')
            ->assertNotified('Whoops! Something went wrong.')
            ->assertNoRedirect();

        expect(session()->has(SessionKey::PROJECT_CREATED->value))->toBeFalse();
    });
});

describe('render', function () {
    it('renders the heading and description', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjects')->andReturn(collect());
        });

        Livewire::test(AddProjectWidget::class)
            ->assertOk()
            ->assertSee('Add project')
            ->assertSee('Click the button below to select a directory and add it as a project.');
    });
});

/**
 * The widget with its native directory picker answering a fixed path.
 */
class AddProjectWidgetWithPickedDirectory extends AddProjectWidget
{
    protected function selectProjectDirectory(): ?string
    {
        return '/tmp/repo';
    }
}

/**
 * The widget with its native directory picker cancelled by the user.
 */
class AddProjectWidgetWithCancelledPicker extends AddProjectWidget
{
    protected function selectProjectDirectory(): ?string
    {
        return null;
    }
}
