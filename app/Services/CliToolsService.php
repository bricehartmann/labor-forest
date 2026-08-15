<?php

namespace App\Services;

use App\Enums\Directory;
use App\Enums\Disk;
use App\Enums\File;
use App\Exceptions\AddCliToolsFailed;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class CliToolsService
{
    public function addCliTools(string $path): void
    {
        $cliToolsPath = Storage::disk(Disk::EXTRAS->value)->path(implode(DIRECTORY_SEPARATOR, [
            Directory::BIN->value,
            File::CLI_TOOLS->value,
        ]));

        $outputPath = $path.DIRECTORY_SEPARATOR.File::CLI_TOOLS->value;

        $shellCmd = sprintf(
            'ln -sf %s %s && chmod +x %s',
            escapeshellarg($cliToolsPath),
            escapeshellarg($outputPath),
            escapeshellarg($outputPath)
        );

        $process = Process::run($shellCmd);

        if ($process->successful()) {
            return;
        }

        $appleScript = sprintf(
            'do shell script "%s" with administrator privileges with prompt "LaborForest wants to add CLI tools."',
            str_replace('"', '\"', $shellCmd)
        );

        $result = Process::run(['osascript', '-e', $appleScript]);

        if (! $result->successful()) {
            throw new AddCliToolsFailed($path);
        }
    }

    public function dismissCliToolsWidget(): void
    {
        $settingsService = app(SettingsService::class);
        $settingsData = $settingsService->loadSettings();
        $settingsData->cli_tools_dismissed = true;
        $settingsService->saveSettings($settingsData);
    }
}
