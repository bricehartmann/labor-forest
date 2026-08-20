<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\ProjectResource;
use App\Mcp\Resources\ProjectsResource;
use App\Mcp\Resources\SettingsResource;
use App\Mcp\Resources\TemplateVariablesResource;
use App\Mcp\Resources\WorkspacesResource;
use App\Mcp\Tools\AddProjectTool;
use App\Mcp\Tools\AddWorkspaceExampleWorkflowsTool;
use App\Mcp\Tools\AddWorkspaceTool;
use App\Mcp\Tools\FindProjectByPathTool;
use App\Mcp\Tools\LaunchBrowserTool;
use App\Mcp\Tools\LaunchIdeTool;
use App\Mcp\Tools\LaunchTerminalTool;
use App\Mcp\Tools\PurgeWorkflowLogsTool;
use App\Mcp\Tools\RemoveProjectTool;
use App\Mcp\Tools\RunWorkflowTool;
use App\Mcp\Tools\UpdateProjectLaunchCommandsTool;
use App\Mcp\Tools\UpdateSettingsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Contracts\Transport;

#[Name('LaborForest')]
#[Instructions(<<<'INSTRUCTIONS'
LaborForest is a macOS desktop app for managing the git worktrees of your local repositories and running local workflows inside them.
It exists to set up, tear down, and otherwise methodically modify local development environments.
Any directory other than the `$HOME` directory that contains a `.laborforest` directory is a workspace.
INSTRUCTIONS)]
class LaborForestServer extends Server
{
    protected array $tools = [
        FindProjectByPathTool::class,
        LaunchIdeTool::class,
        LaunchTerminalTool::class,
        LaunchBrowserTool::class,
        AddProjectTool::class,
        RemoveProjectTool::class,
        AddWorkspaceTool::class,
        AddWorkspaceExampleWorkflowsTool::class,
        RunWorkflowTool::class,
        UpdateSettingsTool::class,
        UpdateProjectLaunchCommandsTool::class,
        PurgeWorkflowLogsTool::class,
    ];

    protected array $resources = [
        SettingsResource::class,
        ProjectsResource::class,
        ProjectResource::class,
        WorkspacesResource::class,
        TemplateVariablesResource::class,
    ];

    protected array $prompts = [
        //
    ];

    /**
     * The version is set here rather than returned from a method, because the package reads the
     * property (or a #[Version] attribute) when it builds the server context and never asks for it.
     */
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);

        $this->version = config('nativephp.version') ?? 'main';
    }
}
