<?php

namespace App\Services;

use App\Concerns\Services\ManagesFiles;
use App\Data\SettingsData;
use App\Enums\File;
use App\Exceptions\InvalidSettingsFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class SettingsService
{
    use ManagesFiles;

    /**
     * @throws InvalidSettingsFile when the file is unparseable, malformed, or fails validation
     */
    public function loadSettings(): SettingsData
    {
        $this->ensureBaseDirectoryExists();
        $this->ensureBaseFileExists(File::SETTINGS->value, Yaml::dump((new SettingsData(
            desktop_notifications: true,
            command_launch_ide: 'open "{{ WORKSPACE_DIR }}" -a phpstorm',
            command_launch_browser: 'open "{{ ENV_APP_URL }}"',
            command_launch_terminal: 'open "{{ WORKSPACE_DIR }}" -a iterm',
        ))->toArray(), inline: 10));

        $path = $this->makeRelativeBasePath(File::SETTINGS->value);

        try {
            $yaml = Yaml::parse($this->getBaseFile(File::SETTINGS->value));
        } catch (ParseException $e) {
            throw InvalidSettingsFile::fromParseError($path, $e);
        }

        if ($yaml !== null && ! is_array($yaml)) {
            throw InvalidSettingsFile::notAMapping($path, get_debug_type($yaml));
        }

        try {
            return SettingsData::validateAndCreate($yaml ?? []);
        } catch (ValidationException $e) {
            throw InvalidSettingsFile::fromValidation($path, $e);
        }
    }

    public function saveSettings(SettingsData $settings): void
    {
        $this->ensureBaseDirectoryExists();
        $this->putBaseFile(File::SETTINGS->value, Yaml::dump($settings->toArray(), inline: 10));
    }
}
