<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\ProjectResource;
use App\Mcp\Resources\ProjectsResource;
use App\Mcp\Resources\SettingsResource;
use App\Mcp\Resources\WorkflowLogResource;
use App\Mcp\Resources\WorkflowLogsResource;
use App\Mcp\Resources\WorkflowResource;
use App\Mcp\Resources\WorkflowsResource;
use App\Mcp\Resources\WorkspaceResource;
use App\Mcp\Resources\WorkspacesResource;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Contracts\Transport;

#[Name('LaborForest')]
#[Instructions(<<<'INSTRUCTIONS'
LaborForest is a macOS desktop app for managing the git worktrees of your local repositories and running local workflows inside them.
It exists to set up, tear down, and otherwise methodically modify local development environments.
INSTRUCTIONS)]
class LaborForestServer extends Server
{
    protected array $tools = [
        //
    ];

    protected array $resources = [
        SettingsResource::class,
        ProjectsResource::class,
        ProjectResource::class,
        WorkspacesResource::class,
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
