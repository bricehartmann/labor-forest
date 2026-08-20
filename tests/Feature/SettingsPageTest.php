<?php

use App\Data\SettingsData;
use App\Enums\McpEndpoint;
use App\Enums\McpServerStatus;
use App\Exceptions\InvalidSettingsFile;
use App\Filament\Pages\Settings;
use App\Services\McpService;
use App\Services\SettingsService;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery\MockInterface;

beforeEach(function () {
    Storage::fake('user_home');

    $this->terminalExample = 'open "{{ WORKSPACE_DIR }}" -a iterm';
    $this->ideExample = 'open "{{ WORKSPACE_DIR }}" -a phpstorm';
    $this->browserExample = 'open "{{ ENV_APP_URL }}"';
});

describe('mount', function () {
    it('fills the form from the loaded settings', function () {
        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')->andReturn(settingsPageSettingsData());
        });

        Livewire::test(Settings::class)
            ->assertOk()
            ->assertSet('loadedInvalidMessage', null)
            ->assertSet('data.dark_mode', false)
            ->assertSet('data.workflow_step_timeout_seconds', 600)
            ->assertSet('data.command_launch_terminal', $this->terminalExample)
            ->assertSet('data.command_launch_ide', $this->ideExample)
            ->assertSet('data.command_launch_browser', $this->browserExample);
    });

    it('records the joined problem strings and falls back to defaults when the settings file is invalid', function () {
        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')->andThrow(new InvalidSettingsFile(
                path: '.laborforest/settings.yaml',
                problems: [
                    'The workflow timeout seconds field must be an integer.',
                    'Unknown variables: {{ NOPE }}.',
                ],
            ));
        });

        Livewire::test(Settings::class)
            ->assertOk()
            ->assertSet(
                'loadedInvalidMessage',
                'The workflow timeout seconds field must be an integer. Unknown variables: {{ NOPE }}.',
            )
            ->assertSet('data.dark_mode', true)
            ->assertSet('data.workflow_step_timeout_seconds', 600)
            ->assertSet('data.command_launch_terminal', null)
            ->assertSet('data.command_launch_ide', null)
            ->assertSet('data.command_launch_browser', null);
    });
});

describe('save', function () {
    it('hands the filled values to the settings service and confirms', function () {
        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')->andReturn(settingsPageSettingsData());
            $mock->shouldReceive('saveSettings')
                ->once()
                ->withArgs(fn (SettingsData $settings) => $settings->dark_mode === true
                    && $settings->workflow_step_timeout_seconds === 90
                    && $settings->command_launch_terminal === 'open "{{ WORKSPACE_DIR }}" -a ghostty'
                    && $settings->command_launch_ide === 'open "{{ WORKSPACE_DIR }}" -a zed'
                    && $settings->command_launch_browser === 'open "{{ ENV_APP_URL }}/dashboard"');
        });

        Livewire::test(Settings::class)
            ->fillForm([
                'dark_mode' => true,
                'workflow_step_timeout_seconds' => 90,
                'command_launch_terminal' => 'open "{{ WORKSPACE_DIR }}" -a ghostty',
                'command_launch_ide' => 'open "{{ WORKSPACE_DIR }}" -a zed',
                'command_launch_browser' => 'open "{{ ENV_APP_URL }}/dashboard"',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Settings saved');
    });

    it('stores a cleared launch command as null', function () {
        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')->andReturn(settingsPageSettingsData());
            $mock->shouldReceive('saveSettings')
                ->once()
                ->withArgs(fn (SettingsData $settings) => $settings->command_launch_terminal === null
                    && $settings->command_launch_ide === null
                    && $settings->command_launch_browser === null);
        });

        Livewire::test(Settings::class)
            ->fillForm([
                'command_launch_terminal' => '',
                'command_launch_ide' => '',
                'command_launch_browser' => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Settings saved');
    });

    it('reports a failure to write the settings file instead of throwing', function () {
        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')->andReturn(settingsPageSettingsData());
            $mock->shouldReceive('saveSettings')
                ->once()
                ->andThrow(new RuntimeException('Permission denied'));
        });

        Livewire::test(Settings::class)
            ->fillForm(['workflow_step_timeout_seconds' => 90])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Whoops! Something went wrong.')
            ->assertNotNotified('Settings saved');
    });
});

describe('mcp server', function () {
    beforeEach(function () {
        $this->settingsAre = function (bool $mcpEnabled, int $mcpPort = 9189) {
            $this->mock(SettingsService::class, function (MockInterface $mock) use ($mcpEnabled, $mcpPort) {
                $mock->shouldReceive('loadSettings')
                    ->andReturn(settingsPageSettingsData(mcpEnabled: $mcpEnabled, mcpPort: $mcpPort));
                $mock->shouldReceive('saveSettings');
            });
        };
    });

    it('starts the server when mcp is switched on', function () {
        ($this->settingsAre)(mcpEnabled: false);

        $this->mock(McpService::class, function (MockInterface $mock) {
            $mock->shouldReceive('startMcpServer')->once();
            $mock->shouldReceive('restartMcpServer')->never();
            $mock->shouldReceive('stopMcpServer')->never();
        });

        Livewire::test(Settings::class)
            ->fillForm(['mcp_enabled' => true, 'mcp_port' => 9189])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Settings saved');
    });

    it('restarts the server on the new port when the port changes', function () {
        ($this->settingsAre)(mcpEnabled: true, mcpPort: 9189);

        $this->mock(McpService::class, function (MockInterface $mock) {
            $mock->shouldReceive('restartMcpServer')->once();
            $mock->shouldReceive('startMcpServer')->never();
            $mock->shouldReceive('stopMcpServer')->never();
        });

        Livewire::test(Settings::class)
            ->fillForm(['mcp_enabled' => true, 'mcp_port' => 9876])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Settings saved');
    });

    it('stops the server when mcp is switched off', function () {
        ($this->settingsAre)(mcpEnabled: true);

        $this->mock(McpService::class, function (MockInterface $mock) {
            $mock->shouldReceive('stopMcpServer')->once();
            $mock->shouldReceive('startMcpServer')->never();
            $mock->shouldReceive('restartMcpServer')->never();
        });

        Livewire::test(Settings::class)
            ->fillForm(['mcp_enabled' => false])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Settings saved');
    });

    it('leaves a healthy server alone when an unrelated setting is saved', function () {
        ($this->settingsAre)(mcpEnabled: true);

        $this->mock(McpService::class, function (MockInterface $mock) {
            $mock->shouldReceive('startMcpServer')->never();
            $mock->shouldReceive('restartMcpServer')->never();
            $mock->shouldReceive('stopMcpServer')->never();
        });

        Livewire::test(Settings::class)
            ->fillForm(['workflow_step_timeout_seconds' => 90])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Settings saved');
    });

    it('leaves the server alone when mcp is saved while it stays switched off', function () {
        ($this->settingsAre)(mcpEnabled: false);

        $this->mock(McpService::class, function (MockInterface $mock) {
            $mock->shouldReceive('startMcpServer')->never();
            $mock->shouldReceive('restartMcpServer')->never();
            $mock->shouldReceive('stopMcpServer')->never();
        });

        Livewire::test(Settings::class)
            ->fillForm(['mcp_enabled' => false, 'workflow_step_timeout_seconds' => 90])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Settings saved');
    });

    it('keeps the port a disabled field never submits', function () {
        // Filament does not dehydrate a disabled field, so `mcp_port` is absent from the saved form
        // state whenever mcp is off; the stored value survives through the merge in save()
        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')->andReturn(settingsPageSettingsData(mcpEnabled: true, mcpPort: 9876));
            $mock->shouldReceive('saveSettings')->once()->withArgs(
                fn (SettingsData $settings) => $settings->mcp_enabled === false && $settings->mcp_port === 9876,
            );
        });

        $this->mock(McpService::class)->shouldReceive('stopMcpServer')->once();

        Livewire::test(Settings::class)
            ->fillForm(['mcp_enabled' => false])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Settings saved');
    });

    it('starts the server when the settings it is replacing could not be read', function () {
        // the previous settings are rescued to null, so nothing is known to be running and the save
        // is treated as switching mcp on
        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')
                ->andThrow(new InvalidSettingsFile('.laborforest/settings.yaml', ['broken']));
        });

        $this->mock(McpService::class, function (MockInterface $mock) {
            $mock->shouldReceive('startMcpServer')->once();
            $mock->shouldReceive('restartMcpServer')->never();
            $mock->shouldReceive('stopMcpServer')->never();
        });

        Livewire::test(Settings::class)
            ->fillForm(['mcp_enabled' => true, 'mcp_port' => 9189])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    it('says nothing beyond the saved confirmation when the server operation succeeds', function () {
        ($this->settingsAre)(mcpEnabled: false);

        $this->mock(McpService::class)->shouldReceive('startMcpServer')->once();

        Livewire::test(Settings::class)
            ->fillForm(['mcp_enabled' => true, 'mcp_port' => 9189])
            ->call('save')
            ->assertNotified('Settings saved')
            ->assertNotNotified('The MCP server could not be updated.');
    });

    it('reports a failed process operation, having still written the settings', function () {
        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')->andReturn(settingsPageSettingsData(mcpEnabled: true));
            $mock->shouldReceive('saveSettings')->once();
        });

        $this->mock(McpService::class, function (MockInterface $mock) {
            $mock->shouldReceive('restartMcpServer')->once()->andThrow(new RuntimeException('Port in use'));
        });

        Livewire::test(Settings::class)
            ->fillForm(['mcp_enabled' => true, 'mcp_port' => 9876])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('The MCP server could not be updated.');
    });
});

describe('mcp endpoint', function () {
    beforeEach(function () {
        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')->andReturn(settingsPageSettingsData(mcpEnabled: true, mcpPort: 9189));
        });
    });

    it('shows the command that registers the endpoint for the saved port', function () {
        Livewire::test(Settings::class)
            ->assertOk()
            ->assertSee('http://127.0.0.1:9189/mcp/laborforest')
            ->assertSee('claude mcp add --transport http laborforest --scope user http://127.0.0.1:9189/mcp/laborforest');
    });

    it('rebuilds the command from the port field before it is saved', function () {
        Livewire::test(Settings::class)
            ->fillForm(['mcp_port' => 9876])
            ->assertSee('http://127.0.0.1:9876/mcp/laborforest')
            ->assertDontSee('http://127.0.0.1:9189/mcp/laborforest');
    });

    it('hides the command when mcp is switched off', function () {
        Livewire::test(Settings::class)
            ->fillForm(['mcp_enabled' => false])
            ->assertDontSee('/mcp/laborforest');
    });
});

describe('test mcp connection', function () {
    beforeEach(function () {
        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')->andReturn(settingsPageSettingsData(mcpEnabled: true, mcpPort: 9189));
        });

        $this->action = TestAction::make('test_mcp_connection')->schemaComponent();
        $this->url = McpEndpoint::LABORFOREST->url(9189);
    });

    /**
     * The real McpService is used here rather than a mock, because the one thing these tests exist to
     * prove is that the port shown beside the button is the port that gets probed. A mock at the
     * service boundary would assert only that the page called a method.
     */
    it('reports the server that answered', function () {
        Http::fake([$this->url => Http::response(mcpInitializeReplyPayload())]);

        Livewire::test(Settings::class)
            ->callAction($this->action)
            ->assertNotified('The MCP server answered');
    });

    it('names the browser guard when the endpoint answers 403', function () {
        Http::fake([$this->url => Http::response('', 403)]);

        Livewire::test(Settings::class)
            ->callAction($this->action)
            ->assertNotified('The endpoint refused the request');
    });

    it('reports nothing listening when the connection is refused', function () {
        Http::fake([$this->url => fn () => throw new ConnectionException('Connection refused')]);

        Livewire::test(Settings::class)
            ->callAction($this->action)
            ->assertNotified('Nothing is listening');
    });

    it('reports something else on a port that is not an mcp server', function () {
        Http::fake([$this->url => Http::response('<html>Nope</html>')]);

        Livewire::test(Settings::class)
            ->callAction($this->action)
            ->assertNotified('Something else is on that port');
    });

    it('probes the port in the form rather than the saved one', function () {
        Http::fake(['http://127.0.0.1:9876/*' => Http::response(mcpInitializeReplyPayload())]);

        Livewire::test(Settings::class)
            ->fillForm(['mcp_port' => 9876])
            ->callAction($this->action)
            ->assertNotified('The MCP server answered');

        Http::assertSent(fn (Request $request) => $request->url() === McpEndpoint::LABORFOREST->url(9876));
    });

    it('reports an endpoint that answers with an error status', function () {
        Http::fake([$this->url => Http::response('', 500)]);

        Livewire::test(Settings::class)
            ->callAction($this->action)
            ->assertNotified('The endpoint answered with an error');
    });

    it('refuses to probe the port the app window itself is served on', function () {
        Livewire::test(Settings::class)
            ->fillForm(['mcp_port' => request()->getPort()])
            ->callAction($this->action)
            ->assertNotified('That port belongs to the app window');

        Http::assertNothingSent();
    });

    it('describes the server it reached, with the icon of a healthy check', function () {
        Http::fake([$this->url => Http::response(mcpInitializeReplyPayload(version: '1.2.3', protocol: '2025-11-25'))]);

        Livewire::test(Settings::class)
            ->callAction($this->action)
            ->assertNotified(
                Notification::make()
                    ->success()
                    ->icon(McpServerStatus::HEALTHY->icon())
                    ->title('The MCP server answered')
                    ->body("LaborForest 1.2.3 answered at {$this->url} over MCP 2025-11-25."),
            );
    });

    it('falls back to the generic failure for an error the check does not name', function () {
        $this->mock(McpService::class, function (MockInterface $mock) {
            $mock->shouldReceive('checkMcpServer')->once()->andThrow(new RuntimeException('Boom'));
        });

        Livewire::test(Settings::class)
            ->callAction($this->action)
            ->assertNotified('Whoops! Something went wrong.');
    });
});

describe('validation', function () {
    beforeEach(function () {
        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')->andReturn(settingsPageSettingsData());
            $mock->shouldReceive('saveSettings')->never();
        });
    });

    it('rejects a timeout that breaks its rule', function (mixed $timeout, string $rule) {
        Livewire::test(Settings::class)
            ->fillForm(['workflow_step_timeout_seconds' => $timeout])
            ->call('save')
            ->assertHasFormErrors(['workflow_step_timeout_seconds' => $rule])
            ->assertNotNotified('Settings saved');
    })->with([
        'missing' => [null, 'required'],
        'not a number' => ['soon', 'numeric'],
        'below the minimum' => [-1, 'min'],
        'above the maximum' => [3601, 'max'],
    ]);

    it('rejects an mcp port that breaks its rule', function (mixed $port, string $rule) {
        Livewire::test(Settings::class)
            ->fillForm(['mcp_enabled' => true, 'mcp_port' => $port])
            ->call('save')
            ->assertHasFormErrors(['mcp_port' => $rule])
            ->assertNotNotified('Settings saved');
    })->with([
        'missing' => [null, 'required'],
        'not a number' => ['nine thousand', 'numeric'],
        'below the first unprivileged port' => [1023, 'min'],
        'above the last registered port' => [49152, 'max'],
    ]);

    it('rejects a launch command that uses an unknown variable', function () {
        Livewire::test(Settings::class)
            ->fillForm([
                'workflow_step_timeout_seconds' => 90,
                'command_launch_terminal' => 'open "{{ NOPE }}" -a iterm',
            ])
            ->call('save')
            ->assertHasFormErrors(['command_launch_terminal'])
            ->assertNotNotified('Settings saved');
    });
});

describe('example suffix actions', function () {
    beforeEach(function () {
        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')->andReturn(new SettingsData);
        });
    });

    it('fills a launch command with its example', function (string $action, string $field, string $example) {
        Livewire::test(Settings::class)
            ->assertSet("data.{$field}", null)
            ->callAction(TestAction::make($action)->schemaComponent($field))
            ->assertSet("data.{$field}", $example);
    })->with([
        'terminal' => ['command_launch_terminal_example', 'command_launch_terminal', 'open "{{ WORKSPACE_DIR }}" -a iterm'],
        'ide' => ['command_launch_ide_example', 'command_launch_ide', 'open "{{ WORKSPACE_DIR }}" -a phpstorm'],
        'browser' => ['command_launch_browser_example', 'command_launch_browser', 'open "{{ ENV_APP_URL }}"'],
    ]);
});

/**
 * Settings as loaded from a valid file, with every launch command populated.
 */
function settingsPageSettingsData(
    bool $darkMode = false,
    int $workflowTimeoutSeconds = 600,
    ?string $ide = 'open "{{ WORKSPACE_DIR }}" -a phpstorm',
    ?string $browser = 'open "{{ ENV_APP_URL }}"',
    ?string $terminal = 'open "{{ WORKSPACE_DIR }}" -a iterm',
    bool $mcpEnabled = true,
    int $mcpPort = 9189,
): SettingsData {
    return new SettingsData(
        dark_mode: $darkMode,
        mcp_enabled: $mcpEnabled,
        mcp_port: $mcpPort,
        workflow_step_timeout_seconds: $workflowTimeoutSeconds,
        command_launch_ide: $ide,
        command_launch_browser: $browser,
        command_launch_terminal: $terminal,
    );
}
