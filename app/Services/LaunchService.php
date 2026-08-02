<?php

namespace App\Services;

use App\Data\ProjectData;
use App\Data\WorkspaceData;

class LaunchService
{
    public function launchTerminal(ProjectData $projectData, WorkspaceData $workspaceData): void
    {
        $this->launch(
            projectData: $projectData,
            workspaceData: $workspaceData,
            command: app(SettingsService::class)->loadSettings()->command_launch_terminal,
        );
    }

    public function launchIde(ProjectData $projectData, WorkspaceData $workspaceData): void
    {
        $this->launch(
            projectData: $projectData,
            workspaceData: $workspaceData,
            command: app(SettingsService::class)->loadSettings()->command_launch_ide,
        );
    }

    public function launchBrowser(ProjectData $projectData, WorkspaceData $workspaceData): void
    {
        $this->launch(
            projectData: $projectData,
            workspaceData: $workspaceData,
            command: app(SettingsService::class)->loadSettings()->command_launch_browser,
        );
    }

    private function launch(ProjectData $projectData, WorkspaceData $workspaceData, ?string $command): void
    {
        if (! $command) {
            return;
        }

        shell_exec(app(VariableReplacementService::class)->replace(
            projectData: $projectData,
            workspaceData: $workspaceData,
            content: $command,
        ));
    }
}
