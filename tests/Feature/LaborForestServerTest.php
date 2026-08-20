<?php

use App\Data\ProjectData;
use App\Data\SettingsData;
use App\Enums\McpUri;
use App\Exceptions\InvalidProjectsFile;
use App\Exceptions\InvalidSettingsFile;
use App\Exceptions\ProjectDirectoryNotFound;
use App\Exceptions\ProjectNotFound;
use App\Exceptions\WorkspaceNotFound;
use App\Mcp\Resources\ProjectResource;
use App\Mcp\Resources\ProjectsResource;
use App\Mcp\Resources\SettingsResource;
use App\Mcp\Servers\LaborForestServer;
use App\Mcp\Tools\AddProjectTool;
use App\Mcp\Tools\AddWorkspaceTool;
use App\Mcp\Tools\FindProjectByPathTool;
use App\Mcp\Tools\LaunchBrowserTool;
use App\Mcp\Tools\LaunchIdeTool;
use App\Mcp\Tools\LaunchTerminalTool;
use App\Mcp\Tools\RemoveProjectTool;
use App\Services\GitService;
use App\Services\LaunchService;
use App\Services\ProjectsService;
use App\Services\SettingsService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\Tool;
use Mockery\MockInterface;

it('reports the application version to connecting clients', function () {
    config(['nativephp.version' => '1.2.3']);

    $server = new LaborForestServer($this->mock(Transport::class));

    expect($server->createContext()->implementation->version)->toBe('1.2.3');
});

it('falls back to the development version when the app version is unset', function () {
    config(['nativephp.version' => null]);

    $server = new LaborForestServer($this->mock(Transport::class));

    expect($server->createContext()->implementation->version)->toBe('main');
});

describe('uris', function () {
    it('fills every placeholder in a templated uri', function () {
        expect(McpUri::PROJECT->build(['uuid' => '22222222-2222-2222-2222-222222222222']))
            ->toBe('laborforest://projects/22222222-2222-2222-2222-222222222222')
            ->and(McpUri::WORKSPACES->build(['uuid' => '22222222-2222-2222-2222-222222222222']))
            ->toBe('laborforest://projects/22222222-2222-2222-2222-222222222222/workspaces');
    });

    it('returns a fixed uri unchanged', function () {
        expect(McpUri::SETTINGS->build())->toBe('laborforest://settings');
    });

    it('refuses to emit a uri with a placeholder left in it', function () {
        expect(fn () => McpUri::PROJECT->build(['slugKebab' => 'beta']))
            ->toThrow(InvalidArgumentException::class, 'Unresolved placeholder in MCP URI [laborforest://projects/{uuid}].');
    });
});

describe('resources', function () {
    it('lists the fixed-uri resources separately from the templated ones', function () {
        $context = (new LaborForestServer($this->mock(Transport::class)))->createContext();

        expect($context->resources()->map(fn (Resource $resource) => $resource->name())->values()->all())
            ->toBe(['settings', 'projects'])
            ->and($context->resources()->map(fn (Resource $resource) => $resource->uri())->values()->all())
            ->toBe(['laborforest://settings', 'laborforest://projects'])
            ->and($context->resourceTemplates()->map(fn (Resource $resource) => $resource->name())->values()->all())
            ->toBe(['project', 'workspaces'])
            ->and($context->resourceTemplates()->map(fn (Resource $resource) => (string) $resource->uriTemplate())->values()->all())
            ->toBe([
                'laborforest://projects/{uuid}',
                'laborforest://projects/{uuid}/workspaces',
            ]);
    });

    it('reads the settings as json', function () {
        $settings = SettingsData::defaults();

        $this->mock(SettingsService::class)
            ->shouldReceive('loadSettings')->once()->andReturn($settings);

        LaborForestServer::resource(SettingsResource::class)
            ->assertOk()
            ->assertSee(mcpJson($settings->toMcpResource()));
    });

    it('reports a settings file it cannot load', function () {
        $this->mock(SettingsService::class)
            ->shouldReceive('loadSettings')->once()->andThrow(new InvalidSettingsFile('.laborforest/settings.yaml', ['broken']));

        LaborForestServer::resource(SettingsResource::class)
            ->assertHasErrors(['Failed to load settings.']);
    });

    it('reads every project as a json array', function () {
        $alpha = componentProjectData('11111111-1111-1111-1111-111111111111', '/tmp/alpha', lastOpened: 1);
        $beta = componentProjectData('22222222-2222-2222-2222-222222222222', '/tmp/beta', lastOpened: 2);

        // ProjectsService::loadProjects() sorts by last_opened, which leaves the keys out of order
        $this->mock(ProjectsService::class)
            ->shouldReceive('loadProjects')->once()
            ->andReturn(collect([$alpha, $beta])->sortByDesc('last_opened'));

        LaborForestServer::resource(ProjectsResource::class)
            ->assertOk()
            ->assertSee(mcpJson([mcpProjectListing($beta), mcpProjectListing($alpha)]));
    });

    it('addresses each listed project by a uri the project template reads', function () {
        $project = componentProjectData('22222222-2222-2222-2222-222222222222', '/tmp/beta');

        $this->mock(ProjectsService::class)
            ->shouldReceive('loadProjects')->twice()->andReturn(collect([$project]));

        LaborForestServer::resource(ProjectsResource::class)
            ->assertOk()
            ->assertSee('laborforest://projects/22222222-2222-2222-2222-222222222222');

        // The listed uri is not merely well formed: it matches the template ProjectResource registers
        $variables = (new ProjectResource)->uriTemplate()->match(
            McpUri::PROJECT->build(['uuid' => $project->uuid]),
        );

        expect($variables)->toBe(['uuid' => $project->uuid]);

        LaborForestServer::resource(ProjectResource::class, $variables)
            ->assertOk()
            ->assertSee(mcpJson($project->toMcpResource()));
    });

    it('reads an empty project list without erroring', function () {
        $this->mock(ProjectsService::class)
            ->shouldReceive('loadProjects')->once()->andReturn(collect());

        LaborForestServer::resource(ProjectsResource::class)
            ->assertOk()
            ->assertSee('[]');
    });

    it('reports a projects file it cannot load', function () {
        $this->mock(ProjectsService::class)
            ->shouldReceive('loadProjects')->once()->andThrow(new InvalidProjectsFile('.laborforest/projects.yaml', ['broken']));

        LaborForestServer::resource(ProjectsResource::class)
            ->assertHasErrors(['Failed to load projects.']);
    });

    it('reads a single project addressed by uuid', function () {
        $wanted = componentProjectData('22222222-2222-2222-2222-222222222222', '/tmp/beta');

        $this->mock(ProjectsService::class)
            ->shouldReceive('loadProjects')->once()->andReturn(collect([
                componentProjectData('11111111-1111-1111-1111-111111111111', '/tmp/alpha'),
                $wanted,
            ]));

        LaborForestServer::resource(ProjectResource::class, ['uuid' => '22222222-2222-2222-2222-222222222222'])
            ->assertOk()
            ->assertSee(mcpJson($wanted->toMcpResource()))
            ->assertDontSee('/tmp/alpha');
    });

    it('reports a uuid that matches no project', function () {
        $this->mock(ProjectsService::class)
            ->shouldReceive('loadProjects')->once()->andReturn(collect());

        LaborForestServer::resource(ProjectResource::class, ['uuid' => '33333333-3333-3333-3333-333333333333'])
            ->assertHasErrors(['Failed to load project.']);
    });
});

describe('tools', function () {
    it('lists the registered tools', function () {
        $context = (new LaborForestServer($this->mock(Transport::class)))->createContext();

        expect($context->tools()->map(fn (Tool $tool) => $tool->name())->values()->all())
            ->toBe([
                'find-project-by-path',
                'launch-ide',
                'launch-terminal',
                'launch-browser',
                'add-project',
                'remove-project',
                'add-workspace',
            ]);
    });

    it('links to the project resource of a matching path', function () {
        $this->mock(ProjectsService::class)
            ->shouldReceive('loadProjects')->once()->andReturn(collect([
                componentProjectData('11111111-1111-1111-1111-111111111111', '/tmp/alpha'),
                componentProjectData('22222222-2222-2222-2222-222222222222', '/tmp/beta'),
            ]));

        // The link content is invisible to assertSee(), which only reads text and blob content
        $response = (new FindProjectByPathTool)->handle(new Request(['path' => '/tmp/beta']));

        expect($response->content()->toArray())->toBe([
            'type' => 'resource_link',
            'uri' => 'laborforest://projects/22222222-2222-2222-2222-222222222222',
            'name' => 'beta',
            'title' => 'beta',
            'description' => '/tmp/beta',
            'mimeType' => 'application/json',
        ]);
    });

    it('ignores a trailing slash on the requested path', function () {
        $this->mock(ProjectsService::class)
            ->shouldReceive('loadProjects')->once()->andReturn(collect([
                componentProjectData('22222222-2222-2222-2222-222222222222', '/tmp/beta'),
            ]));

        $response = (new FindProjectByPathTool)->handle(new Request(['path' => '/tmp/beta/']));

        expect((string) $response->content())->toBe('laborforest://projects/22222222-2222-2222-2222-222222222222');
    });

    it('reports a path that matches no project', function () {
        $this->mock(ProjectsService::class)
            ->shouldReceive('loadProjects')->once()->andReturn(collect());

        LaborForestServer::tool(FindProjectByPathTool::class, ['path' => '/tmp/nope'])
            ->assertHasErrors(['Failed to find project.']);
    });

    it('reports a projects file it cannot load', function () {
        $this->mock(ProjectsService::class)
            ->shouldReceive('loadProjects')->once()->andThrow(new InvalidProjectsFile('.laborforest/projects.yaml', ['broken']));

        LaborForestServer::tool(FindProjectByPathTool::class, ['path' => '/tmp/beta'])
            ->assertHasErrors(['The projects file [.laborforest/projects.yaml] is invalid: broken']);
    });

    it('launches for the workspace at the requested path', function (string $tool, string $launchMethod) {
        $project = componentProjectData('11111111-1111-1111-1111-111111111111', '/tmp/repo');
        $workspace = componentWorkspaceData('/tmp/repo-feature');

        $this->mock(ProjectsService::class, function (MockInterface $mock) use ($project, $workspace) {
            // The trailing slash is trimmed before the workspace is looked up
            $mock->shouldReceive('loadProjectWorkspace')->once()->with('/tmp/repo-feature')->andReturn($workspace);
            $mock->shouldReceive('loadProjectFromWorkspace')->once()->with('/tmp/repo-feature')->andReturn($project);
        });

        $this->mock(LaunchService::class)
            ->shouldReceive($launchMethod)->once()->with($project, $workspace);

        LaborForestServer::tool($tool, ['path' => '/tmp/repo-feature/'])
            ->assertOk()
            ->assertSee('success');
    })->with('launch tools');

    it('reports a workspace path that is not a workspace', function (string $tool) {
        $this->mock(ProjectsService::class)
            ->shouldReceive('loadProjectWorkspace')->once()
            ->andThrow(new WorkspaceNotFound('/tmp/nope'));

        LaborForestServer::tool($tool, ['path' => '/tmp/nope'])
            ->assertHasErrors(["Workspace at path '/tmp/nope' not found."]);
    })->with('launch tools');

    it('reports a workspace whose project is not registered', function (string $tool) {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjectWorkspace')->once()->andReturn(componentWorkspaceData('/tmp/repo-feature'));
            $mock->shouldReceive('loadProjectFromWorkspace')->once()->andReturn(null);
        });

        LaborForestServer::tool($tool, ['path' => '/tmp/repo-feature'])
            ->assertHasErrors(['Failed to find workspace project.']);
    })->with('launch tools');

    it('reports a launch command that fails', function (string $tool, string $launchMethod) {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjectWorkspace')->once()->andReturn(componentWorkspaceData('/tmp/repo-feature'));
            $mock->shouldReceive('loadProjectFromWorkspace')->once()
                ->andReturn(componentProjectData('11111111-1111-1111-1111-111111111111', '/tmp/repo'));
        });

        $this->mock(LaunchService::class)
            ->shouldReceive($launchMethod)->once()
            ->andThrow(new RuntimeException('launch failed'));

        LaborForestServer::tool($tool, ['path' => '/tmp/repo-feature'])
            ->assertHasErrors(['launch failed']);
    })->with('launch tools');

    it('links a new workspace to the project owning it', function () {
        $project = componentProjectData('22222222-2222-2222-2222-222222222222', '/tmp/beta');

        $this->mock(ProjectsService::class, function (MockInterface $mock) use ($project) {
            $mock->shouldReceive('loadProjects')->once()->andReturn(collect([$project]));
            $mock->shouldReceive('addProjectWorkspace')->once()
                ->with($project, 'feature', null)
                ->andReturn(componentWorkspaceData('/tmp/beta-feature'));
        });

        $this->mock(GitService::class)
            ->shouldReceive('doesBranchExist')->once()->with('/tmp/beta', 'feature')->andReturn(true);

        LaborForestServer::tool(AddWorkspaceTool::class, ['path' => '/tmp/beta', 'branch' => 'feature'])
            ->assertOk()
            ->assertSee('success');
    });

    it('reports a uuid that matches no project instead of failing on it', function () {
        $this->mock(ProjectsService::class)
            ->shouldReceive('loadProject')->once()
            ->andThrow(new ProjectNotFound('33333333-3333-3333-3333-333333333333'));

        LaborForestServer::tool(AddWorkspaceTool::class, [
            'uuid' => '33333333-3333-3333-3333-333333333333',
            'branch' => 'feature',
        ])->assertHasErrors(["Project with UUID '33333333-3333-3333-3333-333333333333' not found."]);
    });

    it('reports a project directory it cannot add', function () {
        $this->mock(ProjectsService::class)
            ->shouldReceive('addProject')->once()->with('/tmp/nope')
            ->andThrow(new ProjectDirectoryNotFound('/tmp/nope'));

        LaborForestServer::tool(AddProjectTool::class, ['path' => '/tmp/nope/'])
            ->assertHasErrors([(new ProjectDirectoryNotFound('/tmp/nope'))->getMessage()]);
    });

    it('reports a project it cannot remove', function () {
        $this->mock(ProjectsService::class)
            ->shouldReceive('removeProject')->once()
            ->andThrow(new ProjectNotFound('33333333-3333-3333-3333-333333333333'));

        LaborForestServer::tool(RemoveProjectTool::class, [
            'uuid' => '33333333-3333-3333-3333-333333333333',
            'remove_directory' => false,
            'remove_worktrees' => false,
        ])->assertHasErrors(["Project with UUID '33333333-3333-3333-3333-333333333333' not found."]);
    });
});

dataset('launch tools', [
    'ide' => [LaunchIdeTool::class, 'launchIde'],
    'terminal' => [LaunchTerminalTool::class, 'launchTerminal'],
    'browser' => [LaunchBrowserTool::class, 'launchBrowser'],
]);

/**
 * The JSON exactly as App\Concerns\Mcp\RespondsWithJson encodes it.
 *
 * @param  array<array-key, mixed>  $payload
 */
function mcpJson(array $payload): string
{
    return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * A project exactly as ProjectsResource lists it, addressed by the uri that reads it.
 *
 * @return array<string, mixed>
 */
function mcpProjectListing(ProjectData $project): array
{
    return [
        'uri' => McpUri::PROJECT->build(['uuid' => $project->uuid]),
        ...$project->toMcpResource(),
    ];
}
