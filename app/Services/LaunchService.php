<?php

namespace App\Services;

use App\Data\ProjectData;
use App\Data\WorkspaceData;

class LaunchService
{
    public function launchTerminal(ProjectData $projectData, WorkspaceData $workspaceData): void
    {
        $settings = app(SettingsService::class)->loadSettings();

        if (! $settings->command_launch_terminal) {
            return;
        }

        $command = app(VariableReplacementService::class)->replace(
            projectData: $projectData,
            workspaceData: $workspaceData,
            content: $settings->command_launch_terminal,
        );

        shell_exec($command);
    }
}
