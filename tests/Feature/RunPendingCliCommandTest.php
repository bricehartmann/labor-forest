<?php

use App\Services\CliToolsService;
use Mockery\MockInterface;
use Native\Desktop\Events\App\OpenedFromURL;
use Tests\Fakes\FakeRunPendingCliCommand;

it('sends the window to the page the pending command resolved to', function () {
    $this->mock(CliToolsService::class, function (MockInterface $mock) {
        $mock->shouldReceive('runPendingCommand')
            ->once()
            ->andReturn('http://localhost:8000/projects/some-uuid');
    });

    expect(runPendingCliCommandListener()->navigations)
        ->toBe(['http://localhost:8000/projects/some-uuid']);
});

it('leaves the window alone when nothing was pending', function () {
    $this->mock(CliToolsService::class, function (MockInterface $mock) {
        $mock->shouldReceive('runPendingCommand')
            ->once()
            ->andReturnNull();
    });

    expect(runPendingCliCommandListener()->navigations)->toBe([]);
});

/**
 * Auto-discovery also scans app/Listeners, so an explicit registration alongside a concrete event
 * type hint can bind the listener twice and run the command twice.
 */
it('is bound to the deeplink event exactly once', function () {
    expect(app('events')->getListeners(OpenedFromURL::class))->toHaveCount(1);
});

/**
 * Handle a deeplink with a listener that records navigation instead of performing it, because
 * there is no Electron runtime under test.
 */
function runPendingCliCommandListener(): FakeRunPendingCliCommand
{
    $listener = new FakeRunPendingCliCommand;

    $listener->handle(new OpenedFromURL('laborforest://add-project'));

    return $listener;
}
