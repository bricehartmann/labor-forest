<?php

use App\Exceptions\InstallCliToolsFailed;
use App\Filament\Widgets\InstallCliToolsWidget;
use App\Services\CliToolsService;
use Livewire\Livewire;
use Mockery\MockInterface;

beforeEach(function () {
    $this->pickedPath = '/tmp/bin';
});

describe('render', function () {
    it('renders the heading and description', function () {
        Livewire::test(InstallCliToolsWidget::class)
            ->assertOk()
            ->assertSee('Install CLI tools')
            ->assertSee('Click the button below to select a directory and install the CLI tools script.');
    });

    it('shows the widget whether or not the tools are installed', function () {
        expect(InstallCliToolsWidget::canView())->toBeTrue();
    });

    it('has no dismiss action', function () {
        Livewire::test(InstallCliToolsWidget::class)
            ->assertOk()
            ->assertActionDoesNotExist('dismiss');
    });
});

describe('installCliTools action label', function () {
    it('offers to install when the tools have never been installed', function () {
        Livewire::test(NotInstalledCliToolsWidget::class)
            ->assertOk()
            ->assertActionExists('installCliTools')
            ->assertSee('Install CLI tools');
    });

    it('offers to reinstall once the tools have been installed', function () {
        Livewire::test(InstalledCliToolsWidget::class)
            ->assertOk()
            ->assertSee('Reinstall CLI tools');
    });
});

describe('installCliTools action', function () {
    it('installs the tools into the picked directory', function () {
        $this->mock(CliToolsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('installCliTools')
                ->once()
                ->with($this->pickedPath);
        });

        Livewire::test(CliToolsWidgetWithPickedDirectory::class)
            ->assertOk()
            ->callAction('installCliTools')
            ->assertNotified('CLI tools installed');
    });

    it('does nothing when the directory picker is cancelled', function () {
        $this->mock(CliToolsService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('installCliTools');
        });

        Livewire::test(CliToolsWidgetWithCancelledPicker::class)
            ->assertOk()
            ->callAction('installCliTools')
            ->assertNotNotified();
    });

    it('reports a failure notification when the install fails', function () {
        $this->mock(CliToolsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('installCliTools')
                ->once()
                ->with($this->pickedPath)
                ->andThrow(new InstallCliToolsFailed($this->pickedPath));
        });

        Livewire::test(CliToolsWidgetWithPickedDirectory::class)
            ->assertOk()
            ->callAction('installCliTools')
            ->assertNotified('Whoops! Something went wrong.');
    });
});

/**
 * The widget with its native directory picker answering a fixed path.
 */
class CliToolsWidgetWithPickedDirectory extends InstallCliToolsWidget
{
    protected function selectCliToolsPath(): ?string
    {
        return '/tmp/bin';
    }
}

/**
 * The widget with its native directory picker cancelled by the user.
 */
class CliToolsWidgetWithCancelledPicker extends InstallCliToolsWidget
{
    protected function selectCliToolsPath(): ?string
    {
        return null;
    }
}

/**
 * The widget as it stands before the tools have ever been installed.
 */
class NotInstalledCliToolsWidget extends InstallCliToolsWidget
{
    protected function cliToolsInstalled(): bool
    {
        return false;
    }
}

/**
 * The widget as it stands once a successful install has been recorded.
 */
class InstalledCliToolsWidget extends InstallCliToolsWidget
{
    protected function cliToolsInstalled(): bool
    {
        return true;
    }
}
