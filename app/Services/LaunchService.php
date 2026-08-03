<?php

namespace App\Services;

use App\Data\ProjectData;
use App\Data\WorkspaceData;
use Symfony\Component\Process\Process;

class LaunchService
{
    public function launchTerminal(ProjectData $projectData, WorkspaceData $workspaceData): void
    {
        $this->launch(
            projectData: $projectData,
            workspaceData: $workspaceData,
            command: $projectData->command_launch_terminal ?? app(SettingsService::class)->loadSettings()->command_launch_terminal,
        );
    }

    public function launchIde(ProjectData $projectData, WorkspaceData $workspaceData): void
    {
        $this->launch(
            projectData: $projectData,
            workspaceData: $workspaceData,
            command: $projectData->command_launch_ide ?? app(SettingsService::class)->loadSettings()->command_launch_ide,
        );
    }

    public function launchBrowser(ProjectData $projectData, WorkspaceData $workspaceData): void
    {
        $this->launch(
            projectData: $projectData,
            workspaceData: $workspaceData,
            command: $projectData->command_launch_browser ?? app(SettingsService::class)->loadSettings()->command_launch_browser,
        );
    }

    private function launch(ProjectData $projectData, WorkspaceData $workspaceData, ?string $command): void
    {
        if (! $command) {
            return;
        }

        $process = Process::fromShellCommandline(app(VariableReplacementService::class)->replace(
            projectData: $projectData,
            workspaceData: $workspaceData,
            content: $command,
        ), $workspaceData->path);
        $process->setOptions(['create_new_console' => true]);
        $process->start();
    }
}
