<?php

namespace App\Services;

use App\Concerns\Services\ManagesFiles;
use App\Data\SettingsData;
use App\Enums\File;
use Symfony\Component\Yaml\Yaml;

class SettingsService
{
    use ManagesFiles;

    public function loadSettings(): SettingsData
    {
        $this->ensureBaseDirectoryExists();
        $this->ensureBaseFileExists(File::SETTINGS->value, Yaml::dump([
            'command_open_ide' => null,
            'command_open_browser' => null,
            'command_open_terminal' => null,
        ]));

        $yaml = Yaml::parse($this->getBaseFile(File::SETTINGS->value));

        return SettingsData::from($yaml ?? []);
    }

    public function saveSettings(SettingsData $settings): void
    {
        $this->ensureBaseDirectoryExists();
        $this->putBaseFile(File::SETTINGS->value, Yaml::dump($settings->toArray()));
    }
}
