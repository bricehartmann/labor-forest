<?php

namespace App\Services;

use App\Data\ProjectData;
use App\Data\WorkspaceData;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

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

    protected function launch(ProjectData $projectData, WorkspaceData $workspaceData, ?string $command): void
    {
        if (! $command) {
            return;
        }

        $this->launchProcess(
            command: app(VariableReplacementService::class)->replace(
                projectData: $projectData,
                workspaceData: $workspaceData,
                content: $command,
            ),
            cwd: $workspaceData->path,
        )->start();
    }

    /**
     * Build the launch process, kept as a test seam even though the rest of the application doubles
     * processes with Process::fake().
     *
     * The fake path of PendingProcess::start() builds a process it never starts and then discards
     * it, and create_new_console sends that process's destructor down a branch that reads pipes
     * which were never opened — a fatal error rather than a failed test. The option cannot simply
     * be dropped: without it the destructor stops the process instead, killing the editor or
     * terminal the moment this method returns.
     */
    protected function launchProcess(string $command, string $cwd): PendingProcess
    {
        return Process::path($cwd)
            // a terminal or editor opened here must not inherit this application's environment
            ->env(app(ProcessEnvironmentService::class)->sanitized())
            ->options(['create_new_console' => true])
            ->command($command);
    }
}
