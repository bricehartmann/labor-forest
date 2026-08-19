<?php

use App\Data\SettingsData;
use App\Exceptions\InvalidSettingsFile;
use App\Filament\Pages\Settings;
use App\Services\McpService;
use App\Services\SettingsService;
use Filament\Actions\Testing\TestAction;
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
