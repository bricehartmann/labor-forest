<?php

namespace App\Services;

use App\Concerns\Services\ManagesFiles;
use App\Data\PendingCliCommandData;
use App\Enums\Directory;
use App\Enums\Disk;
use App\Enums\File;
use App\Exceptions\AddCliToolsFailed;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class CliToolsService
{
    use ManagesFiles;

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

    /**
     * Read and remove the request the `lf` script left behind, if there is one.
     *
     * The file is deleted before it is parsed, so a malformed one cannot wedge every future
     * launch, and so a deeplink arriving after the boot drain finds nothing left to run.
     */
    public function pullPendingCommand(): ?PendingCliCommandData
    {
        if (! $this->baseFileExists(File::PENDING_CLI_COMMAND->value)) {
            return null;
        }

        $contents = $this->getBaseFile(File::PENDING_CLI_COMMAND->value);

        $this->deleteBaseFile(File::PENDING_CLI_COMMAND->value);

        try {
            $yaml = Yaml::parse($contents);
        } catch (ParseException) {
            return null;
        }

        if (! is_array($yaml)) {
            return null;
        }

        try {
            return PendingCliCommandData::validateAndCreate($yaml);
        } catch (ValidationException) {
            return null;
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
