<?php

use App\Data\SettingsData;
use App\Enums\McpServerStatus;
use App\Enums\QueryParameter;
use App\Enums\WindowId;
use App\Exceptions\InvalidSettingsFile;
use App\Exceptions\McpServerPortInUse;
use App\Filament\Pages\Dashboard;
use App\Providers\NativeAppServiceProvider;
use App\Services\CliToolsService;
use App\Services\McpService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as NativeWindow;
use Tests\Fakes\RecordingNativeAppServiceProvider;

beforeEach(function () {
    $this->windows = Window::fake()
        ->alwaysReturnWindows([new NativeWindow(WindowId::MAIN->value)]);

    // Without a double here boot() reaches the real McpService, which asks the NativePHP runtime to
    // spawn an artisan process — settings default to mcp_enabled, so every test in this file would
    // otherwise be relying on the rescue() to swallow whatever that does.
    $this->mcp = $this->mock(McpService::class);
    $this->mcp->shouldReceive('startMcpServer')->byDefault();

    // The window the fake hands back reports a URL change to the Electron API over HTTP, so the page
    // boot() lands on is read from a provider that records it instead.
    $this->bootRecording = function (): RecordingNativeAppServiceProvider {
        $provider = new RecordingNativeAppServiceProvider;

        $provider->boot();

        return $provider;
    };

    $this->settingsAre = function (bool $mcpEnabled) {
        $this->mock(SettingsService::class, function (MockInterface $mock) use ($mcpEnabled) {
            $mock->shouldReceive('syncSettingsFile');
            $mock->shouldReceive('loadSettings')->andReturn(new SettingsData(mcp_enabled: $mcpEnabled));
        });
    };
});

describe('boot', function () {
    it('empties the cache', function () {
        Cache::put('anything', 'from a previous launch');

        (new NativeAppServiceProvider)->boot();

        expect(Cache::has('anything'))->toBeFalse();
    });

    it('still opens the main window', function () {
        (new NativeAppServiceProvider)->boot();

        expect($this->windows->opened)->toBe([WindowId::MAIN->value]);
    });
});

describe('mcp server', function () {
    it('starts the server when mcp is enabled in the settings', function () {
        ($this->settingsAre)(mcpEnabled: true);

        $this->mcp->shouldReceive('startMcpServer')->once();

        (new NativeAppServiceProvider)->boot();

        expect($this->windows->opened)->toBe([WindowId::MAIN->value]);
    });

    it('leaves the server alone when mcp is switched off', function () {
        ($this->settingsAre)(mcpEnabled: false);

        $this->mcp->shouldReceive('startMcpServer')->never();

        (new NativeAppServiceProvider)->boot();

        expect($this->windows->opened)->toBe([WindowId::MAIN->value]);
    });

    it('opens the window anyway when the server cannot be started', function () {
        // the window is opened after this point, so a failure that escaped would leave the user with
        // a running app and nothing on screen
        ($this->settingsAre)(mcpEnabled: true);

        $this->mcp->shouldReceive('startMcpServer')->once()->andThrow(new RuntimeException('Port in use'));

        (new NativeAppServiceProvider)->boot();

        expect($this->windows->opened)->toBe([WindowId::MAIN->value]);
    });

    it('lands the window on the reason when something else already owns the port', function () {
        // the server is dead either way, and a client only ever reports that as a failure to connect,
        // so the app says so on the way in rather than leaving the user to find out from the client
        ($this->settingsAre)(mcpEnabled: true);

        $url = 'http://127.0.0.1:9189/mcp/laborforest';

        $this->mcp->shouldReceive('startMcpServer')
            ->once()
            ->andThrow(new McpServerPortInUse(McpServerStatus::STALE, $url));

        expect(($this->bootRecording)()->navigations)->toBe([Dashboard::getUrl([
            QueryParameter::ERROR->value => 'The MCP server could not be started',
            QueryParameter::BODY->value => McpServerStatus::STALE->message($url),
        ])]);
    });

    it('leaves the window on the dashboard for a failure the user cannot act on', function () {
        ($this->settingsAre)(mcpEnabled: true);

        $this->mcp->shouldReceive('startMcpServer')->once()->andThrow(new RuntimeException('Transient'));

        expect(($this->bootRecording)()->navigations)->toBe([]);
    });

    it('keeps a pending cli request ahead of the port it could not have', function () {
        // the user asked for that page by name; the MCP failure is still reported, by report()
        ($this->settingsAre)(mcpEnabled: true);

        $this->mock(CliToolsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('runPendingCommand')->andReturn('http://localhost/projects/some-uuid');
        });

        $this->mcp->shouldReceive('startMcpServer')
            ->once()
            ->andThrow(new McpServerPortInUse(McpServerStatus::STALE, 'http://127.0.0.1:9189/mcp/laborforest'));

        expect(($this->bootRecording)()->navigations)->toBe(['http://localhost/projects/some-uuid']);
    });

    it('treats settings it cannot read as mcp being switched off', function () {
        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('syncSettingsFile');
            $mock->shouldReceive('loadSettings')
                ->andThrow(new InvalidSettingsFile('.laborforest/settings.yaml', ['broken']));
        });

        $this->mcp->shouldReceive('startMcpServer')->never();

        (new NativeAppServiceProvider)->boot();

        expect($this->windows->opened)->toBe([WindowId::MAIN->value]);
    });
});
