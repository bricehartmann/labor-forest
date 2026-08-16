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
            ->and($settings->workflow_step_timeout_seconds)->toBe(600);
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
            'cli_tools_dismissed' => false,
            'workflow_step_timeout_seconds' => 45,
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
            'command_launch_ide',
            'command_launch_browser',
            'command_launch_terminal',
        ])->and($written['dark_mode'])->toBeFalse();
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
