<?php

use App\Data\SettingsData;
use App\Enums\WindowId;
use App\Exceptions\InvalidSettingsFile;
use App\Providers\NativeAppServiceProvider;
use App\Services\McpService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as NativeWindow;

beforeEach(function () {
    Storage::fake('user_home');

    $this->windows = Window::fake()
        ->alwaysReturnWindows([new NativeWindow(WindowId::MAIN->value)]);

    // Without a double here boot() reaches the real McpService, which asks the NativePHP runtime to
    // spawn an artisan process — settings default to mcp_enabled, so every test in this file would
    // otherwise be relying on the rescue() to swallow whatever that does.
    $this->mcp = $this->mock(McpService::class);
    $this->mcp->shouldReceive('startMcpServer')->byDefault();

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
