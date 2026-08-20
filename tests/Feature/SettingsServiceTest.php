<?php

use App\Data\SettingsData;
use App\Exceptions\InvalidSettingsFile;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->disk = Storage::fake('user_home');
    $this->path = '.laborforest/settings.yaml';
    $this->settings = new SettingsService;
});

describe('loadSettings', function () {
    it('seeds the file with the defaults when it does not exist', function () {
        $settings = $this->settings->loadSettings();

        expect($settings)->toBeInstanceOf(SettingsData::class)
            ->and($settings->toArray())->toBe(SettingsData::defaults()->toArray())
            ->and($this->disk->exists($this->path))->toBeTrue()
            ->and(Yaml::parse($this->disk->get($this->path)))->toBe(SettingsData::defaults()->toArray());
    });

    it('reads the values stored in the file', function () {
        $this->disk->put($this->path, settingsYaml([
            'dark_mode' => false,
            'workflow_step_timeout_seconds' => 30,
        ]));

        $settings = $this->settings->loadSettings();

        expect($settings->dark_mode)->toBeFalse()
            ->and($settings->workflow_step_timeout_seconds)->toBe(30);
    });

    it('fills missing keys from the defaults', function () {
        $this->disk->put($this->path, Yaml::dump(['dark_mode' => false], inline: 10));

        $settings = $this->settings->loadSettings();

        expect($settings->dark_mode)->toBeFalse()
            ->and($settings->command_launch_ide)->toBe(SettingsData::defaults()->command_launch_ide)
            ->and($settings->workflow_step_timeout_seconds)->toBe(600)
            ->and($settings->mcp_enabled)->toBeTrue()
            ->and($settings->mcp_port)->toBe(9189);
    });

    it('returns the defaults for an empty file rather than throwing', function () {
        $this->disk->put($this->path, '');

        expect($this->settings->loadSettings()->toArray())->toBe(SettingsData::defaults()->toArray());
    });

    it('throws when the file is not parseable yaml', function () {
        $this->disk->put($this->path, "dark_mode: [unclosed\n");

        expect(fn () => $this->settings->loadSettings())
            ->toThrow(InvalidSettingsFile::class, 'The settings file [.laborforest/settings.yaml] is invalid:');
    });

    it('throws when the file parses to something other than a mapping', function () {
        $this->disk->put($this->path, "just a string\n");

        expect(fn () => $this->settings->loadSettings())
            ->toThrow(InvalidSettingsFile::class, 'Expected a mapping, found string.');
    });

    it('throws when the contents fail validation', function () {
        $this->disk->put($this->path, settingsYaml(['workflow_step_timeout_seconds' => -1]));

        expect(fn () => $this->settings->loadSettings())
            ->toThrow(InvalidSettingsFile::class, 'workflow step timeout seconds');
    });

    it('throws when the mcp switch is not a boolean', function () {
        $this->disk->put($this->path, settingsYaml(['mcp_enabled' => 'sure']));

        expect(fn () => $this->settings->loadSettings())
            ->toThrow(InvalidSettingsFile::class, 'mcp enabled');
    });

    it('throws when the mcp port is outside the range a server can be reached on', function (mixed $port) {
        // the same bounds the settings screen enforces, so a hand-edited file cannot name a port the
        // form would have refused
        $this->disk->put($this->path, settingsYaml(['mcp_port' => $port]));

        expect(fn () => $this->settings->loadSettings())
            ->toThrow(InvalidSettingsFile::class, 'mcp port');
    })->with([
        'not a number' => ['nine thousand'],
        'below the first unprivileged port' => [1023],
        'above the last registered port' => [49152],
    ]);

    it('reads an mcp port at either end of the range', function (int $port) {
        $this->disk->put($this->path, settingsYaml(['mcp_port' => $port]));

        expect($this->settings->loadSettings()->mcp_port)->toBe($port);
    })->with([
        'first' => [1024],
        'last' => [49151],
    ]);

    it('reports every validation problem at once', function () {
        $this->disk->put($this->path, settingsYaml([
            'dark_mode' => 'nope',
            'workflow_step_timeout_seconds' => -1,
        ]));

        try {
            $this->settings->loadSettings();
        } catch (InvalidSettingsFile $e) {
            expect($e->problems)->toHaveCount(2)
                ->and($e->path)->toBe($this->path);

            return;
        }

        $this->fail('Expected an InvalidSettingsFile exception.');
    });
});

describe('saveSettings', function () {
    it('writes the settings to the file', function () {
        $this->settings->saveSettings(new SettingsData(dark_mode: false, workflow_step_timeout_seconds: 45));

        expect(Yaml::parse($this->disk->get($this->path)))->toBe([
            'dark_mode' => false,
            'cli_tools_installed' => false,
            'workflow_step_timeout_seconds' => 45,
            'mcp_enabled' => true,
            'mcp_port' => 9189,
            'command_launch_ide' => null,
            'command_launch_browser' => null,
            'command_launch_terminal' => null,
        ]);
    });

    it('overwrites the previous contents', function () {
        $this->disk->put($this->path, settingsYaml(['workflow_step_timeout_seconds' => 30]));

        $this->settings->saveSettings(new SettingsData(workflow_step_timeout_seconds: 45));

        expect(Yaml::parse($this->disk->get($this->path))['workflow_step_timeout_seconds'])->toBe(45);
    });
});

describe('syncSettingsFile', function () {
    it('adds the keys the file is missing without changing the ones it has', function () {
        $this->disk->put($this->path, Yaml::dump(['dark_mode' => false], inline: 10));

        $this->settings->syncSettingsFile();

        $written = Yaml::parse($this->disk->get($this->path));

        expect($written)->toHaveKeys([
            'dark_mode',
            'workflow_step_timeout_seconds',
            'mcp_enabled',
            'mcp_port',
            'command_launch_ide',
            'command_launch_browser',
            'command_launch_terminal',
        ])->and($written['dark_mode'])->toBeFalse()
            // the upgrade path for a settings file written before the mcp server existed
            ->and($written['mcp_enabled'])->toBe(SettingsData::defaults()->mcp_enabled)
            ->and($written['mcp_port'])->toBe(SettingsData::defaults()->mcp_port);
    });

    it('throws and leaves the file untouched when it is invalid', function () {
        $this->disk->put($this->path, "just a string\n");

        expect(fn () => $this->settings->syncSettingsFile())
            ->toThrow(InvalidSettingsFile::class)
            ->and($this->disk->get($this->path))->toBe("just a string\n");
    });
});

/**
 * Dump a full settings file, overriding any of the default keys.
 *
 * @param  array<string, mixed>  $overrides
 */
function settingsYaml(array $overrides = []): string
{
    return Yaml::dump(array_merge(SettingsData::defaults()->toArray(), $overrides), inline: 10);
}
