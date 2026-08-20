<?php

use App\Data\ProjectData;
use App\Data\SettingsData;
use App\Data\WorkflowStepData;
use App\Data\WorkspaceData;
use App\Enums\McpUri;
use App\Enums\Variable;
use App\Enums\WorkflowStepType;
use App\Enums\WorkspaceStatus;
use App\Events\GlobalRefresh;
use App\Exceptions\GitOperationFailed;
use App\Exceptions\InvalidProjectsFile;
use App\Exceptions\InvalidSettingsFile;
use App\Exceptions\InvalidWorkflowFile;
use App\Exceptions\ProjectDirectoryNotFound;
use App\Exceptions\ProjectNotFound;
use App\Exceptions\WorkflowLogsNotDeleted;
use App\Exceptions\WorkflowNotRunnable;
use App\Exceptions\WorkspaceDirectoryExists;
use App\Exceptions\WorkspaceNotFound;
use App\Mcp\Prompts\AuthorWorkflowPrompt;
use App\Mcp\Prompts\ConvertSetupToWorkflowPrompt;
use App\Mcp\Prompts\DiagnoseWorkflowRunPrompt;
use App\Mcp\Resources\ProjectResource;
use App\Mcp\Resources\ProjectsResource;
use App\Mcp\Resources\SettingsResource;
use App\Mcp\Resources\TemplateVariablesResource;
use App\Mcp\Resources\WorkflowSchemaResource;
use App\Mcp\Resources\WorkspacesResource;
use App\Mcp\Servers\LaborForestServer;
use App\Mcp\Tools\AddProjectTool;
use App\Mcp\Tools\AddWorkspaceExampleWorkflowsTool;
use App\Mcp\Tools\AddWorkspaceTool;
use App\Mcp\Tools\FindProjectByPathTool;
use App\Mcp\Tools\LaunchBrowserTool;
use App\Mcp\Tools\LaunchIdeTool;
use App\Mcp\Tools\LaunchTerminalTool;
use App\Mcp\Tools\OverrideWorkspaceStatusTool;
use App\Mcp\Tools\PurgeWorkflowLogsTool;
use App\Mcp\Tools\RemoveProjectTool;
use App\Mcp\Tools\RunWorkflowTool;
use App\Mcp\Tools\UpdateProjectLaunchCommandsTool;
use App\Mcp\Tools\UpdateSettingsTool;
use App\Mcp\Tools\ValidateWorkflowTool;
use App\Services\GitService;
use App\Services\LaunchService;
use App\Services\ProjectsService;
use App\Services\SettingsService;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Prompt;
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

it('tells connecting clients what the server is for and what it will not do', function () {
    $instructions = (new LaborForestServer($this->mock(Transport::class)))->createContext()->instructions;

    // the load-bearing claims rather than the prose, so the wording stays editable but a dropped fact fails
    expect($instructions)
        // the three nouns every tool argument is phrased in
        ->toContain('**Project**')
        ->toContain('**Workspace**')
        ->toContain('**Workflow**')
        // a queued run is the fact a client cannot infer from the tool schema
        ->toContain('queues the run and returns immediately')
        // the statuses the run gate turns on
        ->toContain('Only `ready` and `suspended` may run anything at all')
        // nothing else warns that a tool call runs the user's shell unprompted
        ->toContain('Nothing on the LaborForest side asks the user to confirm a tool call.')
        // the settings the server refuses to change out from under its own client
        ->toContain('cannot change `mcp_enabled`, `mcp_port`, `mcp_read_only` or the token')
        // a short tool list is a mode the user chose, not something to route around
        ->toContain('read-only mode')
        // no tool writes a workflow file, so the grammar has to be fetched before one is written
        ->toContain('laborforest://workflow-schema')
        // the run logs are files on disk, because nothing here serves them
        ->toContain('.laborforest/ignored/logs/');
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
            ->toBe(['settings', 'projects', 'template-variables', 'workflow-schema'])
            ->and($context->resources()->map(fn (Resource $resource) => $resource->uri())->values()->all())
            ->toBe([
                'laborforest://settings',
                'laborforest://projects',
                'laborforest://template-variables',
                'laborforest://workflow-schema',
            ])
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

    it('withholds the bearer token, which a client that got this far already holds', function () {
        $settings = new SettingsData(mcp_token: Str::random(SettingsData::MCP_TOKEN_LENGTH));

        $this->mock(SettingsService::class)
            ->shouldReceive('loadSettings')->once()->andReturn($settings);

        LaborForestServer::resource(SettingsResource::class)
            ->assertOk()
            ->assertDontSee($settings->mcp_token);

        expect($settings->toMcpResource())->not->toHaveKey('mcp_token')
            ->and($settings->toMcpResource())->toHaveKey('mcp_read_only');
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

    it('carries the derived names a project resource is read for', function () {
        // spelled out rather than rebuilt from toMcpResource(), which is the method under test: the
        // assertions above compare it against itself and would survive the keys being dropped
        $this->mock(ProjectsService::class)
            ->shouldReceive('loadProjects')->once()->andReturn(collect([
                componentProjectData('22222222-2222-2222-2222-222222222222', '/tmp/repos/My Repo'),
            ]));

        LaborForestServer::resource(ProjectResource::class, ['uuid' => '22222222-2222-2222-2222-222222222222'])
            ->assertOk()
            ->assertSee('"dir_name":"My Repo"')
            ->assertSee('"parent_dir":"/tmp/repos"')
            ->assertSee('"slug_kebab":"my-repo"')
            ->assertSee('"slug_snake":"my_repo"');
    });

    it('reads the workspaces of a project as a json array', function () {
        $project = componentProjectData('22222222-2222-2222-2222-222222222222', '/tmp/repo');
        $workspaces = collect([
            componentWorkspaceData('/tmp/repo', isPrimary: true, branch: 'main'),
            componentWorkspaceData('/tmp/repo-feature'),
        ]);

        $this->mock(ProjectsService::class, function (MockInterface $mock) use ($project, $workspaces) {
            $mock->shouldReceive('loadProjects')->once()->andReturn(collect([$project]));
            $mock->shouldReceive('loadProjectWorkspaces')->once()->with('/tmp/repo')->andReturn($workspaces);
        });

        LaborForestServer::resource(WorkspacesResource::class, ['uuid' => $project->uuid])
            ->assertOk()
            ->assertSee(mcpJson($workspaces->map(fn (WorkspaceData $data) => $data->toMcpResource())->all()));
    });

    it('carries the derived names a workspace resource is read for', function () {
        $project = componentProjectData('22222222-2222-2222-2222-222222222222', '/tmp/repo');

        $this->mock(ProjectsService::class, function (MockInterface $mock) use ($project) {
            $mock->shouldReceive('loadProjects')->once()->andReturn(collect([$project]));
            $mock->shouldReceive('loadProjectWorkspaces')->once()
                ->andReturn(collect([componentWorkspaceData('/tmp/repos/My Repo-feature')]));
        });

        LaborForestServer::resource(WorkspacesResource::class, ['uuid' => $project->uuid])
            ->assertOk()
            ->assertSee('"dir_name":"My Repo-feature"')
            ->assertSee('"parent_dir":"/tmp/repos"')
            ->assertSee('"slug_kebab":"my-repo-feature"')
            ->assertSee('"slug_snake":"my_repo_feature"');
    });

    it('addresses the workspaces of a project by a uri the workspaces template reads', function () {
        $project = componentProjectData('22222222-2222-2222-2222-222222222222', '/tmp/repo');

        $variables = (new WorkspacesResource)->uriTemplate()->match(
            McpUri::WORKSPACES->build(['uuid' => $project->uuid]),
        );

        expect($variables)->toBe(['uuid' => $project->uuid]);

        $this->mock(ProjectsService::class, function (MockInterface $mock) use ($project) {
            $mock->shouldReceive('loadProjects')->once()->andReturn(collect([$project]));
            $mock->shouldReceive('loadProjectWorkspaces')->once()->andReturn(collect());
        });

        LaborForestServer::resource(WorkspacesResource::class, $variables)->assertOk();
    });

    it('reads an empty workspace list for a project with no worktrees', function () {
        // loadProjectWorkspaces() rescues a git failure to an empty collection of its own, so this
        // is what a project whose worktrees cannot be listed answers with as well
        $project = componentProjectData('22222222-2222-2222-2222-222222222222', '/tmp/repo');

        $this->mock(ProjectsService::class, function (MockInterface $mock) use ($project) {
            $mock->shouldReceive('loadProjects')->once()->andReturn(collect([$project]));
            $mock->shouldReceive('loadProjectWorkspaces')->once()->andReturn(collect());
        });

        LaborForestServer::resource(WorkspacesResource::class, ['uuid' => $project->uuid])
            ->assertOk()
            ->assertSee('[]');
    });

    it('reports a uuid whose workspaces cannot be read because the project is unknown', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjects')->once()->andReturn(collect());
            $mock->shouldReceive('loadProjectWorkspaces')->never();
        });

        LaborForestServer::resource(WorkspacesResource::class, ['uuid' => '33333333-3333-3333-3333-333333333333'])
            ->assertHasErrors(['Failed to load project.']);
    });

    it('reports a projects file it cannot read the workspaces of a project from', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjects')->once()
                ->andThrow(new InvalidProjectsFile('.laborforest/projects.yaml', ['broken']));
            $mock->shouldReceive('loadProjectWorkspaces')->never();
        });

        LaborForestServer::resource(WorkspacesResource::class, ['uuid' => '22222222-2222-2222-2222-222222222222'])
            ->assertHasErrors(['Failed to load project.']);
    });

    it('reads every template variable with the example it is documented by', function () {
        $expected = collect(Variable::cases())
            ->map(fn (Variable $variable) => ['variable' => $variable->value, 'example' => $variable->example()])
            ->all();

        LaborForestServer::resource(TemplateVariablesResource::class)
            ->assertOk()
            ->assertSee(mcpJson($expected))
            // the enumerated variables alone: the dynamic ENV_ prefix is deliberately not listed
            ->assertSee('"variable":"WORKSPACE_DIR"')
            ->assertDontSee('ENV_');
    });

    it('reads the workflow schema as json', function () {
        LaborForestServer::resource(WorkflowSchemaResource::class)
            ->assertOk()
            ->assertName('workflow-schema')
            // the file is only read as a workflow when it declares this, and only from this directory
            ->assertSee('"directory":".laborforest/workflows"')
            ->assertSee('"resource_type":{"required":true,"value":"workflow"}')
            // the ENV_ passthrough the template-variables resource deliberately omits
            ->assertSee('ENV_APP_URL');
    });

    it('builds the workflow schema from the enums the app validates against', function () {
        $response = LaborForestServer::resource(WorkflowSchemaResource::class);

        // every step type, each with the key it cannot be written without
        foreach (WorkflowStepType::cases() as $type) {
            $response->assertSee('"type":"'.$type->value.'","requires":"'.$type->requiredKey().'"');
        }

        // every variable, and only the statuses a workflow file is allowed to declare
        foreach (Variable::cases() as $variable) {
            $response->assertSee('"variable":"'.$variable->value.'"');
        }

        $response->assertSee(mcpJson(WorkspaceStatus::declarableInWorkflowValues()));
    });

    /**
     * The schema names the key each step type requires, while the rules that actually reject a step
     * missing it live on WorkflowStepData. Nothing but this holds the two together.
     */
    it('names a required step key the step rules agree is required', function (WorkflowStepType $type) {
        expect(WorkflowStepData::rules()[$type->requiredKey()])
            ->toContain('required_if:type,'.$type->value);
    })->with(WorkflowStepType::cases());
});

describe('prompts', function () {
    it('lists the registered prompts', function () {
        $context = (new LaborForestServer($this->mock(Transport::class)))->createContext();

        expect($context->prompts()->map(fn (Prompt $prompt) => $prompt->name())->values()->all())
            ->toBe(['author-workflow', 'convert-setup-to-workflow', 'diagnose-workflow-run']);
    });

    it('hands back instructions for writing one workflow', function () {
        LaborForestServer::prompt(AuthorWorkflowPrompt::class, [
            'path' => '/tmp/repo-feature/',
            'goal' => 'bring the workspace up',
            'name' => 'up',
        ])
            ->assertOk()
            ->assertName('author-workflow')
            // the grammar is fetched rather than recalled, because no tool writes a workflow file
            ->assertSee('laborforest://workflow-schema')
            ->assertSee('no tool on this server writes workflow files')
            // the check that has no consequences, as against starting a run
            ->assertSee('validate-workflow')
            ->assertSee('Do not run the workflow to test it.')
            // the trailing slash is trimmed, as everywhere else a path is taken
            ->assertSee('/tmp/repo-feature/.laborforest/workflows')
            ->assertDontSee('/tmp/repo-feature//.laborforest');
    });

    it('names the workflow to write when one was given', function () {
        LaborForestServer::prompt(AuthorWorkflowPrompt::class, [
            'path' => '/tmp/repo-feature',
            'goal' => 'bring the workspace up',
            'name' => 'up',
        ])->assertSee('Write the workflow `up`');
    });

    it('leaves the workflow name to be chosen when none was given', function () {
        LaborForestServer::prompt(AuthorWorkflowPrompt::class, [
            'path' => '/tmp/repo-feature',
            'goal' => 'bring the workspace up',
        ])->assertOk()->assertSee('choose a file name for it');
    });

    it('hands back instructions for reading the run logs off disk', function () {
        LaborForestServer::prompt(DiagnoseWorkflowRunPrompt::class, ['path' => '/tmp/repo-feature'])
            ->assertOk()
            ->assertName('diagnose-workflow-run')
            // no tool serves the logs, so the directory itself is the answer
            ->assertSee('/tmp/repo-feature/.laborforest/ignored/logs')
            // the fields that separate the step that failed from the ones that never ran
            ->assertSee('exitCode')
            ->assertSee('skip_reason: aborted')
            // a nested workflow's failure is in the child's own log
            ->assertSee('log_id')
            // the status a failed run leaves behind, and the tool that clears it
            ->assertSee('override-workspace-status');
    });

    it('scopes the diagnosis to one workflow when it was named', function () {
        LaborForestServer::prompt(DiagnoseWorkflowRunPrompt::class, [
            'path' => '/tmp/repo-feature',
            'workflow' => 'up',
        ])->assertSee('the `up` workflow');
    });

    it('hands back instructions for converting existing setup steps', function () {
        LaborForestServer::prompt(ConvertSetupToWorkflowPrompt::class, ['path' => '/tmp/repo-feature'])
            ->assertOk()
            ->assertName('convert-setup-to-workflow')
            ->assertSee('laborforest://workflow-schema')
            // the status pairing that makes a workspace lifecycle reversible
            ->assertSee('requires `suspended`, ends `ready`')
            ->assertSee('requires `ready`, ends `suspended`')
            // what keeps two workspaces of one project from sharing a database
            ->assertSee('update_env')
            ->assertSee('validate-workflow');
    });

    it('starts the conversion from the source it was given', function () {
        LaborForestServer::prompt(ConvertSetupToWorkflowPrompt::class, [
            'path' => '/tmp/repo-feature',
            'source' => 'the Makefile',
        ])->assertSee('starting from the Makefile');
    });

    it('refuses a prompt invoked without the workspace it acts on', function (string $prompt) {
        LaborForestServer::prompt($prompt, [])->assertHasErrors();
    })->with([
        AuthorWorkflowPrompt::class,
        ConvertSetupToWorkflowPrompt::class,
        DiagnoseWorkflowRunPrompt::class,
    ]);

    it('refuses to author a workflow without knowing what it should do', function () {
        LaborForestServer::prompt(AuthorWorkflowPrompt::class, ['path' => '/tmp/repo-feature'])
            ->assertHasErrors();
    });
});

describe('tools', function () {
    it('lists the registered tools', function () {
        $this->mock(SettingsService::class)
            ->shouldReceive('loadSettings')->once()->andReturn(mcpWritableSettings());

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
                'add-workspace-example-workflows',
                'validate-workflow',
                'run-workflow',
                'override-workspace-status',
                'update-settings',
                'update-project-launch-commands',
                'purge-workflow-logs',
            ]);
    });

    it('publishes only the tools that change nothing while read-only mode is on', function () {
        $this->mock(SettingsService::class)
            ->shouldReceive('loadSettings')->once()
            ->andReturn(new SettingsData(mcp_read_only: true));

        $context = (new LaborForestServer($this->mock(Transport::class)))->createContext();

        expect($context->tools()->map(fn (Tool $tool) => $tool->name())->values()->all())
            ->toBe([
                'find-project-by-path',
                'validate-workflow',
            ]);
    });

    it('counts the launch tools as writes, since they spawn a command the user configured', function () {
        $this->mock(SettingsService::class)
            ->shouldReceive('loadSettings')->once()
            ->andReturn(new SettingsData(mcp_read_only: true));

        $context = (new LaborForestServer($this->mock(Transport::class)))->createContext();

        expect($context->tools()->map(fn (Tool $tool) => $tool->name())->values()->all())
            ->not->toContain('launch-ide', 'launch-terminal', 'launch-browser');
    });

    it('publishes every tool when the settings file cannot be read, rather than a shortened list', function () {
        $this->mock(SettingsService::class)
            ->shouldReceive('loadSettings')->once()
            ->andThrow(new InvalidSettingsFile('.laborforest/settings.yaml', ['broken']));

        $context = (new LaborForestServer($this->mock(Transport::class)))->createContext();

        expect($context->tools())->toHaveCount(14);
    });

    it('marks running a workflow destructive, because a step is arbitrary shell', function () {
        $context = (new LaborForestServer($this->mock(Transport::class)))->createContext();

        $annotations = $context->tools()->first(fn (Tool $tool) => $tool->name() === 'run-workflow')->annotations();

        expect($annotations['destructiveHint'] ?? null)->toBeTrue();
    });

    it('does not call a launch tool read-only, which would tell a client there is nothing to confirm', function (string $name) {
        $context = (new LaborForestServer($this->mock(Transport::class)))->createContext();

        $annotations = $context->tools()->first(fn (Tool $tool) => $tool->name() === $name)->annotations();

        expect($annotations['readOnlyHint'] ?? false)->toBeFalse();
    })->with(['launch-ide', 'launch-terminal', 'launch-browser']);

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
        Event::fake([GlobalRefresh::class]);

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

        Event::assertDispatched(GlobalRefresh::class);
    });

    it('links to the resource of a newly added project', function () {
        Event::fake([GlobalRefresh::class]);

        $this->mock(ProjectsService::class)
            ->shouldReceive('addProject')->once()->with('/tmp/beta')
            ->andReturn(componentProjectData('22222222-2222-2222-2222-222222222222', '/tmp/beta'));

        // The trailing slash is trimmed before the project is added
        $response = (new AddProjectTool)->handle(new Request(['path' => '/tmp/beta/']));

        expect($response->content()->toArray())->toBe([
            'type' => 'resource_link',
            'uri' => 'laborforest://projects/22222222-2222-2222-2222-222222222222',
            'name' => 'beta',
            'title' => 'beta',
            'description' => '/tmp/beta',
            'mimeType' => 'application/json',
        ]);

        Event::assertDispatched(GlobalRefresh::class);
    });

    it('removes the project the uuid names', function () {
        Event::fake([GlobalRefresh::class]);

        $this->mock(ProjectsService::class)
            ->shouldReceive('removeProject')->once()
            ->with('22222222-2222-2222-2222-222222222222', true, false);

        LaborForestServer::tool(RemoveProjectTool::class, [
            'uuid' => '22222222-2222-2222-2222-222222222222',
            'remove_directory' => true,
            'remove_worktrees' => false,
        ])->assertOk()->assertSee('success');

        Event::assertDispatched(GlobalRefresh::class);
    });

    it('seeds the chosen example workflows into the workspace', function () {
        Event::fake([GlobalRefresh::class]);

        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('listExampleWorkflowPaths')->andReturn(collect([
                'example-workflows/bare',
                'example-workflows/laravel',
            ]));
            $mock->shouldReceive('loadProjectWorkspace')->once()->with('/tmp/repo-feature')
                ->andReturn(componentWorkspaceData('/tmp/repo-feature'));
            $mock->shouldReceive('loadProjectFromWorkspace')->once()->with('/tmp/repo-feature')
                ->andReturn(componentProjectData('11111111-1111-1111-1111-111111111111', '/tmp/repo'));
            $mock->shouldReceive('initializeWorkspaceStarterWorkflows')->once()
                ->with('/tmp/repo-feature', 'example-workflows/laravel');
        });

        LaborForestServer::tool(AddWorkspaceExampleWorkflowsTool::class, [
            'path' => '/tmp/repo-feature',
            'example' => 'laravel',
        ])->assertOk()->assertSee('success');

        Event::assertDispatched(GlobalRefresh::class);
    });

    it('refuses an example workflow set it does not offer', function () {
        Event::fake([GlobalRefresh::class]);

        $this->mock(ProjectsService::class)
            ->shouldReceive('listExampleWorkflowPaths')->andReturn(collect(['example-workflows/bare']));

        LaborForestServer::tool(AddWorkspaceExampleWorkflowsTool::class, [
            'path' => '/tmp/repo-feature',
            'example' => 'nope',
        ])->assertHasErrors();

        Event::assertNotDispatched(GlobalRefresh::class);
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

    it('creates a branch that does not exist yet from the base branch it is given', function () {
        Event::fake([GlobalRefresh::class]);

        $project = componentProjectData('22222222-2222-2222-2222-222222222222', '/tmp/beta');

        $this->mock(ProjectsService::class, function (MockInterface $mock) use ($project) {
            $mock->shouldReceive('loadProjects')->once()->andReturn(collect([$project]));
            $mock->shouldReceive('addProjectWorkspace')->once()
                ->with($project, 'feature', 'main')
                ->andReturn(componentWorkspaceData('/tmp/beta-feature'));
        });

        $this->mock(GitService::class)
            ->shouldReceive('doesBranchExist')->once()->with('/tmp/beta', 'feature')->andReturn(false);

        LaborForestServer::tool(AddWorkspaceTool::class, [
            'path' => '/tmp/beta',
            'branch' => 'feature',
            'base_branch' => 'main',
        ])->assertOk()->assertSee('success');

        Event::assertDispatched(GlobalRefresh::class);
    });

    it('requires a base branch only for a branch that does not exist yet', function () {
        Event::fake([GlobalRefresh::class]);

        $project = componentProjectData('22222222-2222-2222-2222-222222222222', '/tmp/beta');

        $this->mock(ProjectsService::class, function (MockInterface $mock) use ($project) {
            $mock->shouldReceive('loadProjects')->once()->andReturn(collect([$project]));
            $mock->shouldReceive('addProjectWorkspace')->never();
        });

        $this->mock(GitService::class)
            ->shouldReceive('doesBranchExist')->once()->with('/tmp/beta', 'feature')->andReturn(false);

        LaborForestServer::tool(AddWorkspaceTool::class, ['path' => '/tmp/beta', 'branch' => 'feature'])
            ->assertHasErrors(['The base branch field is required.']);

        Event::assertNotDispatched(GlobalRefresh::class);
    });

    it('reports a repository whose branches it cannot list', function () {
        $project = componentProjectData('22222222-2222-2222-2222-222222222222', '/tmp/beta');

        $this->mock(ProjectsService::class, function (MockInterface $mock) use ($project) {
            $mock->shouldReceive('loadProjects')->once()->andReturn(collect([$project]));
            $mock->shouldReceive('addProjectWorkspace')->never();
        });

        $this->mock(GitService::class)
            ->shouldReceive('doesBranchExist')->once()
            ->andThrow(new GitOperationFailed('list branches', 'not a git repository'));

        LaborForestServer::tool(AddWorkspaceTool::class, ['path' => '/tmp/beta', 'branch' => 'feature'])
            ->assertHasErrors([(new GitOperationFailed('list branches', 'not a git repository'))->getMessage()]);
    });

    it('reports a workspace directory it cannot create', function () {
        Event::fake([GlobalRefresh::class]);

        $project = componentProjectData('22222222-2222-2222-2222-222222222222', '/tmp/beta');

        $this->mock(ProjectsService::class, function (MockInterface $mock) use ($project) {
            $mock->shouldReceive('loadProjects')->once()->andReturn(collect([$project]));
            $mock->shouldReceive('addProjectWorkspace')->once()
                ->andThrow(new WorkspaceDirectoryExists('/tmp/beta-feature'));
        });

        $this->mock(GitService::class)
            ->shouldReceive('doesBranchExist')->once()->andReturn(true);

        LaborForestServer::tool(AddWorkspaceTool::class, ['path' => '/tmp/beta', 'branch' => 'feature'])
            ->assertHasErrors([(new WorkspaceDirectoryExists('/tmp/beta-feature'))->getMessage()]);

        Event::assertNotDispatched(GlobalRefresh::class);
    });

    it('refuses to add a workspace naming neither a path nor a uuid', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjects')->never();
            $mock->shouldReceive('loadProject')->never();
        });

        LaborForestServer::tool(AddWorkspaceTool::class, ['branch' => 'feature'])
            ->assertHasErrors(['The path field is required when uuid is not present.']);
    });

    it('names the example workflow sets it does offer when it refuses one', function () {
        $this->mock(ProjectsService::class)
            ->shouldReceive('listExampleWorkflowPaths')->andReturn(collect([
                'example-workflows/bare',
                'example-workflows/laravel',
            ]));

        LaborForestServer::tool(AddWorkspaceExampleWorkflowsTool::class, [
            'path' => '/tmp/repo-feature',
            'example' => 'nope',
        ])->assertHasErrors(['The selected example is invalid.']);
    });

    it('reports a path that is not a workspace to seed example workflows into', function () {
        Event::fake([GlobalRefresh::class]);

        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('listExampleWorkflowPaths')->andReturn(collect(['example-workflows/bare']));
            $mock->shouldReceive('loadProjectWorkspace')->once()->with('/tmp/nope')
                ->andThrow(new WorkspaceNotFound('/tmp/nope'));
            $mock->shouldReceive('initializeWorkspaceStarterWorkflows')->never();
        });

        LaborForestServer::tool(AddWorkspaceExampleWorkflowsTool::class, [
            'path' => '/tmp/nope',
            'example' => 'bare',
        ])->assertHasErrors(["Workspace at path '/tmp/nope' not found."]);

        Event::assertNotDispatched(GlobalRefresh::class);
    });

    it('reports a workspace whose project is not registered when seeding example workflows', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('listExampleWorkflowPaths')->andReturn(collect(['example-workflows/bare']));
            $mock->shouldReceive('loadProjectWorkspace')->once()->with('/tmp/repo-feature')
                ->andReturn(componentWorkspaceData('/tmp/repo-feature'));
            $mock->shouldReceive('loadProjectFromWorkspace')->once()->andReturnNull();
            $mock->shouldReceive('initializeWorkspaceStarterWorkflows')->never();
        });

        LaborForestServer::tool(AddWorkspaceExampleWorkflowsTool::class, [
            'path' => '/tmp/repo-feature',
            'example' => 'bare',
        ])->assertHasErrors(['Failed to find workspace project.']);
    });

    it('reports example workflows it cannot write into the workspace', function () {
        Event::fake([GlobalRefresh::class]);

        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('listExampleWorkflowPaths')->andReturn(collect(['example-workflows/bare']));
            $mock->shouldReceive('loadProjectWorkspace')->once()->with('/tmp/repo-feature')
                ->andReturn(componentWorkspaceData('/tmp/repo-feature'));
            $mock->shouldReceive('loadProjectFromWorkspace')->once()
                ->andReturn(componentProjectData('11111111-1111-1111-1111-111111111111', '/tmp/repo'));
            $mock->shouldReceive('initializeWorkspaceStarterWorkflows')->once()
                ->andThrow(new RuntimeException('Permission denied'));
        });

        LaborForestServer::tool(AddWorkspaceExampleWorkflowsTool::class, [
            'path' => '/tmp/repo-feature',
            'example' => 'bare',
        ])->assertHasErrors(['Permission denied']);

        Event::assertNotDispatched(GlobalRefresh::class);
    });

    it('reports a project directory it cannot add', function () {
        $this->mock(ProjectsService::class)
            ->shouldReceive('addProject')->once()->with('/tmp/nope')
            ->andThrow(new ProjectDirectoryNotFound('/tmp/nope'));

        LaborForestServer::tool(AddProjectTool::class, ['path' => '/tmp/nope/'])
            ->assertHasErrors([(new ProjectDirectoryNotFound('/tmp/nope'))->getMessage()]);
    });

    it('reads back the workflow it validated for the workspace at the path', function () {
        $workflowPath = '/tmp/repo-feature/.laborforest/workflows/up.yaml';

        mcpWorkspaceIsResolved();
        mcpWorkflowFileExists($workflowPath);

        $this->mock(WorkflowService::class, function (MockInterface $mock) use ($workflowPath) {
            $mock->shouldReceive('workflowPath')->once()->with('/tmp/repo-feature', 'up')->andReturn($workflowPath);
            $mock->shouldReceive('loadWorkflow')->once()->with($workflowPath)->andReturn(componentWorkflowData(
                [
                    componentStepData(),
                    componentStepData(name: 'Run down', run: 'down', type: WorkflowStepType::WORKFLOW),
                ],
                requireStatus: WorkspaceStatus::SUSPENDED,
                endingStatus: WorkspaceStatus::READY,
            ));
            $mock->shouldReceive('dispatchWorkflow')->never();
        });

        // The trailing slash is trimmed before the workspace is looked up
        LaborForestServer::tool(ValidateWorkflowTool::class, ['path' => '/tmp/repo-feature/', 'workflow' => 'up'])
            ->assertOk()
            ->assertSee(mcpJson([
                'workflow' => 'up',
                'path' => $workflowPath,
                'require_status' => 'suspended',
                'ending_status' => 'ready',
                'steps' => [
                    ['name' => 'Install dependencies', 'type' => 'shell'],
                    ['name' => 'Run down', 'type' => 'workflow'],
                ],
            ]));
    });

    it('reads back a workflow written with the yml extension', function () {
        $workflowPath = '/tmp/repo-feature/.laborforest/workflows/up.yml';

        mcpWorkspaceIsResolved();
        mcpWorkflowFileExists($workflowPath);

        $this->mock(WorkflowService::class, function (MockInterface $mock) use ($workflowPath) {
            $mock->shouldReceive('workflowPath')->once()->with('/tmp/repo-feature', 'up')->andReturn($workflowPath);
            $mock->shouldReceive('loadWorkflow')->once()->with($workflowPath)->andReturn(componentWorkflowData([componentStepData()]));
        });

        LaborForestServer::tool(ValidateWorkflowTool::class, ['path' => '/tmp/repo-feature', 'workflow' => 'up'])
            ->assertOk()
            ->assertSee($workflowPath);
    });

    it('reports a workflow name matching no file before validating it', function () {
        mcpWorkspaceIsResolved();
        mcpWorkflowFileExists('/tmp/repo-feature/.laborforest/workflows/up.yaml');

        $this->mock(WorkflowService::class, function (MockInterface $mock) {
            $mock->shouldReceive('workflowPath')->once()->with('/tmp/repo-feature', 'down')
                ->andReturn('/tmp/repo-feature/.laborforest/workflows/down.yaml');
            $mock->shouldReceive('loadWorkflow')->never();
        });

        LaborForestServer::tool(ValidateWorkflowTool::class, ['path' => '/tmp/repo-feature', 'workflow' => 'down'])
            ->assertHasErrors(["Workflow 'down' does not exist."]);
    });

    it('reports every structural problem of a workflow it cannot load', function () {
        $workflowPath = '/tmp/repo-feature/.laborforest/workflows/up.yaml';

        mcpWorkspaceIsResolved();
        mcpWorkflowFileExists($workflowPath);

        $this->mock(WorkflowService::class, function (MockInterface $mock) use ($workflowPath) {
            $mock->shouldReceive('workflowPath')->once()->andReturn($workflowPath);
            $mock->shouldReceive('loadWorkflow')->once()->andThrow(InvalidWorkflowFile::withProblems($workflowPath, [
                'The steps field is required.',
                'The sort order field must be an integer.',
            ]));
        });

        LaborForestServer::tool(ValidateWorkflowTool::class, ['path' => '/tmp/repo-feature', 'workflow' => 'up'])
            ->assertHasErrors([
                "The workflow file [{$workflowPath}] is invalid: The steps field is required. The sort order field must be an integer.",
            ]);
    });

    it('reports a path that is not a workspace to validate a workflow in', function () {
        $this->mock(ProjectsService::class)
            ->shouldReceive('loadProjectWorkspace')->once()->with('/tmp/nope')
            ->andThrow(new WorkspaceNotFound('/tmp/nope'));

        $this->mock(WorkflowService::class)->shouldReceive('loadWorkflow')->never();

        LaborForestServer::tool(ValidateWorkflowTool::class, ['path' => '/tmp/nope', 'workflow' => 'up'])
            ->assertHasErrors(["Workspace at path '/tmp/nope' not found."]);
    });

    it('dispatches every step of the named workflow for the workspace at the path', function () {
        $project = componentProjectData('11111111-1111-1111-1111-111111111111', '/tmp/repo');
        $workflowPath = '/tmp/repo-feature/.laborforest/workflows/up.yaml';

        $this->mock(ProjectsService::class, function (MockInterface $mock) use ($project) {
            // The trailing slash is trimmed before the workspace is looked up
            $mock->shouldReceive('loadProjectWorkspace')->once()->with('/tmp/repo-feature')
                ->andReturn(componentWorkspaceData('/tmp/repo-feature'));
            $mock->shouldReceive('loadProjectFromWorkspace')->once()->with('/tmp/repo-feature')->andReturn($project);
        });

        $this->mock(SettingsService::class)
            ->shouldReceive('loadSettings')->twice()
            ->andReturn(mcpWritableSettings(new SettingsData(workflow_step_timeout_seconds: 45)));

        $this->mock(WorkflowService::class, function (MockInterface $mock) use ($workflowPath) {
            $mock->shouldReceive('workflowPath')->once()->with('/tmp/repo-feature', 'up')->andReturn($workflowPath);

            // null step selection: the tool runs the whole workflow, as `lf run` does
            $mock->shouldReceive('dispatchWorkflow')->once()
                ->with('11111111-1111-1111-1111-111111111111', '/tmp/repo-feature', 'up', null, null, 45)
                ->andReturn('20240101T000000Z_repo-feature_up');
        });

        mcpWorkflowFileExists($workflowPath);

        LaborForestServer::tool(RunWorkflowTool::class, ['path' => '/tmp/repo-feature/', 'workflow' => 'up'])
            ->assertOk()
            ->assertSee('20240101T000000Z_repo-feature_up');
    });

    it('reports a workflow name matching no file before reading it', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjectWorkspace')->once()->andReturn(componentWorkspaceData('/tmp/repo-feature'));
            $mock->shouldReceive('loadProjectFromWorkspace')->once()
                ->andReturn(componentProjectData('11111111-1111-1111-1111-111111111111', '/tmp/repo'));
        });

        $this->mock(WorkflowService::class, function (MockInterface $mock) {
            $mock->shouldReceive('workflowPath')->once()->with('/tmp/repo-feature', 'down')
                ->andReturn('/tmp/repo-feature/.laborforest/workflows/down.yaml');
            $mock->shouldNotReceive('dispatchWorkflow');
        });

        mcpWorkflowFileExists('/tmp/repo-feature/.laborforest/workflows/up.yaml');

        LaborForestServer::tool(RunWorkflowTool::class, ['path' => '/tmp/repo-feature', 'workflow' => 'down'])
            ->assertHasErrors(["Workflow 'down' does not exist."]);
    });

    it('reports a workflow the workspace is not in a position to run', function () {
        $workflowPath = '/tmp/repo-feature/.laborforest/workflows/up.yaml';

        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjectWorkspace')->once()->andReturn(componentWorkspaceData('/tmp/repo-feature'));
            $mock->shouldReceive('loadProjectFromWorkspace')->once()
                ->andReturn(componentProjectData('11111111-1111-1111-1111-111111111111', '/tmp/repo'));
        });

        $this->mock(SettingsService::class)
            ->shouldReceive('loadSettings')->twice()->andReturn(mcpWritableSettings(new SettingsData));

        $this->mock(WorkflowService::class, function (MockInterface $mock) use ($workflowPath) {
            $mock->shouldReceive('workflowPath')->once()->andReturn($workflowPath);
            $mock->shouldReceive('dispatchWorkflow')->once()
                ->andThrow(new WorkflowNotRunnable('up', WorkspaceStatus::READY, WorkspaceStatus::SUSPENDED));
        });

        mcpWorkflowFileExists($workflowPath);

        LaborForestServer::tool(RunWorkflowTool::class, ['path' => '/tmp/repo-feature', 'workflow' => 'up'])
            ->assertHasErrors(['Workflow [up] requires the workspace to be suspended, but it is ready.']);
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

    it('writes each updated setting to its own field', function () {
        Event::fake([GlobalRefresh::class]);

        $saved = null;

        $this->mock(SettingsService::class, function (MockInterface $mock) use (&$saved) {
            $mock->shouldReceive('loadSettings')->twice()->andReturn(mcpWritableSettings());
            $mock->shouldReceive('saveSettings')->once()
                ->andReturnUsing(function (SettingsData $settings) use (&$saved) {
                    $saved = $settings;
                });
        });

        LaborForestServer::tool(UpdateSettingsTool::class, [
            'dark_mode' => false,
            'workflow_step_timeout_seconds' => 45,
            'command_launch_ide' => 'open "{{ WORKSPACE_DIR }}" -a zed',
            'command_launch_browser' => 'open "{{ ENV_APP_URL }}" -a safari',
            'command_launch_terminal' => 'open "{{ WORKSPACE_DIR }}" -a ghostty',
        ])->assertOk()->assertSee('success');

        expect($saved->dark_mode)->toBeFalse()
            ->and($saved->workflow_step_timeout_seconds)->toBe(45)
            ->and($saved->command_launch_ide)->toBe('open "{{ WORKSPACE_DIR }}" -a zed')
            ->and($saved->command_launch_browser)->toBe('open "{{ ENV_APP_URL }}" -a safari')
            ->and($saved->command_launch_terminal)->toBe('open "{{ WORKSPACE_DIR }}" -a ghostty');

        Event::assertDispatched(GlobalRefresh::class);
    });

    it('leaves an omitted or null setting at the value it already held', function () {
        Event::fake([GlobalRefresh::class]);

        $saved = null;

        $this->mock(SettingsService::class, function (MockInterface $mock) use (&$saved) {
            $mock->shouldReceive('loadSettings')->twice()->andReturn(mcpWritableSettings());
            $mock->shouldReceive('saveSettings')->once()
                ->andReturnUsing(function (SettingsData $settings) use (&$saved) {
                    $saved = $settings;
                });
        });

        LaborForestServer::tool(UpdateSettingsTool::class, [
            'workflow_step_timeout_seconds' => 45,
            'command_launch_ide' => null,
        ])->assertOk()->assertSee('success');

        $defaults = SettingsData::defaults();

        expect($saved->workflow_step_timeout_seconds)->toBe(45)
            ->and($saved->dark_mode)->toBe($defaults->dark_mode)
            ->and($saved->command_launch_ide)->toBe($defaults->command_launch_ide)
            ->and($saved->command_launch_browser)->toBe($defaults->command_launch_browser)
            ->and($saved->command_launch_terminal)->toBe($defaults->command_launch_terminal);

        Event::assertDispatched(GlobalRefresh::class);
    });

    it('refuses a launch command naming a variable it does not recognize', function () {
        Event::fake([GlobalRefresh::class]);

        $this->mock(SettingsService::class)->shouldNotReceive('saveSettings');

        LaborForestServer::tool(UpdateSettingsTool::class, [
            'command_launch_terminal' => 'open "{{ NOPE }}"',
        ])->assertHasErrors(['Unknown variables: {{ NOPE }}.']);

        Event::assertNotDispatched(GlobalRefresh::class);
    });

    it('reports a settings file it cannot read before updating it', function () {
        Event::fake([GlobalRefresh::class]);

        $this->mock(SettingsService::class)
            ->shouldReceive('loadSettings')->twice()
            ->andThrow(new InvalidSettingsFile('.laborforest/settings.yaml', ['broken']));

        LaborForestServer::tool(UpdateSettingsTool::class, ['dark_mode' => false])
            ->assertHasErrors(['The settings file [.laborforest/settings.yaml] is invalid: broken']);

        Event::assertNotDispatched(GlobalRefresh::class);
    });

    it('clears a global launch command given an empty string', function () {
        Event::fake([GlobalRefresh::class]);

        $saved = null;

        $this->mock(SettingsService::class, function (MockInterface $mock) use (&$saved) {
            $mock->shouldReceive('loadSettings')->twice()->andReturn(mcpWritableSettings());
            $mock->shouldReceive('saveSettings')->once()
                ->andReturnUsing(function (SettingsData $settings) use (&$saved) {
                    $saved = $settings;
                });
        });

        LaborForestServer::tool(UpdateSettingsTool::class, [
            'command_launch_ide' => '',
        ])->assertOk()->assertSee('success');

        expect($saved->command_launch_ide)->toBeNull()
            ->and($saved->command_launch_browser)->toBe(SettingsData::defaults()->command_launch_browser);

        Event::assertDispatched(GlobalRefresh::class);
    });

    it('writes each launch command override to the project at the path', function () {
        Event::fake([GlobalRefresh::class]);

        $saved = null;

        $this->mock(ProjectsService::class, function (MockInterface $mock) use (&$saved) {
            $mock->shouldReceive('loadProjects')->once()->andReturn(collect([
                componentProjectData('22222222-2222-2222-2222-222222222222', '/tmp/beta'),
            ]));
            $mock->shouldReceive('updateProject')->once()
                ->andReturnUsing(function (ProjectData $project) use (&$saved) {
                    $saved = $project;
                });
        });

        // The trailing slash is trimmed before the project is looked up
        LaborForestServer::tool(UpdateProjectLaunchCommandsTool::class, [
            'path' => '/tmp/beta/',
            'command_launch_ide' => 'open "{{ WORKSPACE_DIR }}" -a zed',
            'command_launch_browser' => 'open "{{ ENV_APP_URL }}" -a safari',
            'command_launch_terminal' => 'open "{{ WORKSPACE_DIR }}" -a ghostty',
        ])->assertOk()->assertSee('success');

        expect($saved->uuid)->toBe('22222222-2222-2222-2222-222222222222')
            ->and($saved->command_launch_ide)->toBe('open "{{ WORKSPACE_DIR }}" -a zed')
            ->and($saved->command_launch_browser)->toBe('open "{{ ENV_APP_URL }}" -a safari')
            ->and($saved->command_launch_terminal)->toBe('open "{{ WORKSPACE_DIR }}" -a ghostty');

        Event::assertDispatched(GlobalRefresh::class);
    });

    it('writes a launch command override to the project the uuid names', function () {
        Event::fake([GlobalRefresh::class]);

        $saved = null;

        $this->mock(ProjectsService::class, function (MockInterface $mock) use (&$saved) {
            $mock->shouldReceive('loadProject')->once()->with('22222222-2222-2222-2222-222222222222')
                ->andReturn(componentProjectData('22222222-2222-2222-2222-222222222222', '/tmp/beta'));
            $mock->shouldReceive('updateProject')->once()
                ->andReturnUsing(function (ProjectData $project) use (&$saved) {
                    $saved = $project;
                });
        });

        LaborForestServer::tool(UpdateProjectLaunchCommandsTool::class, [
            'uuid' => '22222222-2222-2222-2222-222222222222',
            'command_launch_ide' => 'open "{{ WORKSPACE_DIR }}" -a zed',
        ])->assertOk()->assertSee('success');

        expect($saved->command_launch_ide)->toBe('open "{{ WORKSPACE_DIR }}" -a zed');

        Event::assertDispatched(GlobalRefresh::class);
    });

    it('clears a launch command override given an empty string', function () {
        Event::fake([GlobalRefresh::class]);

        $saved = null;

        $this->mock(ProjectsService::class, function (MockInterface $mock) use (&$saved) {
            $mock->shouldReceive('loadProjects')->once()->andReturn(collect([
                componentProjectData(
                    '22222222-2222-2222-2222-222222222222',
                    '/tmp/beta',
                    ide: 'open "{{ WORKSPACE_DIR }}" -a zed',
                    browser: 'open "{{ ENV_APP_URL }}" -a safari',
                ),
            ]));
            $mock->shouldReceive('updateProject')->once()
                ->andReturnUsing(function (ProjectData $project) use (&$saved) {
                    $saved = $project;
                });
        });

        LaborForestServer::tool(UpdateProjectLaunchCommandsTool::class, [
            'path' => '/tmp/beta',
            'command_launch_ide' => '',
        ])->assertOk()->assertSee('success');

        // a cleared override is stored as null rather than as the blank it arrived as, so the
        // project falls back to the global command
        expect($saved->command_launch_ide)->toBeNull()
            ->and($saved->command_launch_browser)->toBe('open "{{ ENV_APP_URL }}" -a safari');

        Event::assertDispatched(GlobalRefresh::class);
    });

    it('leaves an omitted or null launch command override at the value it already held', function () {
        Event::fake([GlobalRefresh::class]);

        $saved = null;

        $this->mock(ProjectsService::class, function (MockInterface $mock) use (&$saved) {
            $mock->shouldReceive('loadProjects')->once()->andReturn(collect([
                componentProjectData(
                    '22222222-2222-2222-2222-222222222222',
                    '/tmp/beta',
                    ide: 'open "{{ WORKSPACE_DIR }}" -a zed',
                    browser: 'open "{{ ENV_APP_URL }}" -a safari',
                    terminal: 'open "{{ WORKSPACE_DIR }}" -a ghostty',
                ),
            ]));
            $mock->shouldReceive('updateProject')->once()
                ->andReturnUsing(function (ProjectData $project) use (&$saved) {
                    $saved = $project;
                });
        });

        LaborForestServer::tool(UpdateProjectLaunchCommandsTool::class, [
            'path' => '/tmp/beta',
            'command_launch_ide' => null,
        ])->assertOk()->assertSee('success');

        expect($saved->command_launch_ide)->toBe('open "{{ WORKSPACE_DIR }}" -a zed')
            ->and($saved->command_launch_browser)->toBe('open "{{ ENV_APP_URL }}" -a safari')
            ->and($saved->command_launch_terminal)->toBe('open "{{ WORKSPACE_DIR }}" -a ghostty');

        Event::assertDispatched(GlobalRefresh::class);
    });

    it('refuses a launch command override naming a variable it does not recognize', function () {
        Event::fake([GlobalRefresh::class]);

        $this->mock(ProjectsService::class)->shouldNotReceive('updateProject');

        LaborForestServer::tool(UpdateProjectLaunchCommandsTool::class, [
            'path' => '/tmp/beta',
            'command_launch_terminal' => 'open "{{ NOPE }}"',
        ])->assertHasErrors(['Unknown variables: {{ NOPE }}.']);

        Event::assertNotDispatched(GlobalRefresh::class);
    });

    it('refuses a launch command override naming neither a path nor a uuid', function () {
        Event::fake([GlobalRefresh::class]);

        $this->mock(ProjectsService::class)->shouldNotReceive('updateProject');

        LaborForestServer::tool(UpdateProjectLaunchCommandsTool::class, [
            'command_launch_ide' => 'open "{{ WORKSPACE_DIR }}" -a zed',
        ])->assertHasErrors(['The path field is required when uuid is not present.']);

        Event::assertNotDispatched(GlobalRefresh::class);
    });

    it('reports a launch command override path that matches no project', function () {
        Event::fake([GlobalRefresh::class]);

        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjects')->once()->andReturn(collect());
            $mock->shouldNotReceive('updateProject');
        });

        LaborForestServer::tool(UpdateProjectLaunchCommandsTool::class, [
            'path' => '/tmp/nope',
            'command_launch_ide' => 'open "{{ WORKSPACE_DIR }}" -a zed',
        ])->assertHasErrors(['Failed to find project.']);

        Event::assertNotDispatched(GlobalRefresh::class);
    });

    it('reports a launch command override uuid that matches no project', function () {
        Event::fake([GlobalRefresh::class]);

        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProject')->once()
                ->andThrow(new ProjectNotFound('33333333-3333-3333-3333-333333333333'));
            $mock->shouldNotReceive('updateProject');
        });

        LaborForestServer::tool(UpdateProjectLaunchCommandsTool::class, [
            'uuid' => '33333333-3333-3333-3333-333333333333',
            'command_launch_ide' => 'open "{{ WORKSPACE_DIR }}" -a zed',
        ])->assertHasErrors(["Project with UUID '33333333-3333-3333-3333-333333333333' not found."]);

        Event::assertNotDispatched(GlobalRefresh::class);
    });

    it('reports a projects file it cannot write the launch command override to', function () {
        Event::fake([GlobalRefresh::class]);

        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjects')->once()->andReturn(collect([
                componentProjectData('22222222-2222-2222-2222-222222222222', '/tmp/beta'),
            ]));
            $mock->shouldReceive('updateProject')->once()
                ->andThrow(new InvalidProjectsFile('.laborforest/projects.yaml', ['broken']));
        });

        LaborForestServer::tool(UpdateProjectLaunchCommandsTool::class, [
            'path' => '/tmp/beta',
            'command_launch_ide' => 'open "{{ WORKSPACE_DIR }}" -a zed',
        ])->assertHasErrors(['The projects file [.laborforest/projects.yaml] is invalid: broken']);

        Event::assertNotDispatched(GlobalRefresh::class);
    });

    it('purges the run logs of the named workflow in the workspace at the path', function () {
        Event::fake([GlobalRefresh::class]);

        mcpWorkspaceIsResolved();

        $this->mock(WorkflowService::class)
            ->shouldReceive('purgeWorkflowLogs')->once()
            ->with(Mockery::on(fn (WorkspaceData $workspace) => $workspace->path === '/tmp/repo-feature'), 'up')
            ->andReturn(['purged' => 4, 'skipped' => 0]);

        // The trailing slash is trimmed before the workspace is looked up
        LaborForestServer::tool(PurgeWorkflowLogsTool::class, [
            'path' => '/tmp/repo-feature/',
            'workflow' => 'up',
        ])->assertOk()->assertSee('Purged 4 log records.');

        Event::assertDispatched(GlobalRefresh::class);
    });

    it('reports the runs it left alone alongside the ones it purged', function () {
        Event::fake([GlobalRefresh::class]);

        mcpWorkspaceIsResolved();

        $this->mock(WorkflowService::class)
            ->shouldReceive('purgeWorkflowLogs')->once()->andReturn(['purged' => 4, 'skipped' => 1]);

        LaborForestServer::tool(PurgeWorkflowLogsTool::class, [
            'path' => '/tmp/repo-feature',
            'workflow' => 'up',
        ])->assertOk()->assertSee('Purged 4 log records. Skipped 1 still in progress.');

        Event::assertDispatched(GlobalRefresh::class);
    });

    it('reports purging nothing rather than failing on a workflow with no run logs', function () {
        Event::fake([GlobalRefresh::class]);

        mcpWorkspaceIsResolved();

        // no check that the workflow file exists: logs outlive the workflow that wrote them
        $this->mock(WorkflowService::class)
            ->shouldReceive('purgeWorkflowLogs')->once()->andReturn(['purged' => 0, 'skipped' => 0]);

        LaborForestServer::tool(PurgeWorkflowLogsTool::class, [
            'path' => '/tmp/repo-feature',
            'workflow' => 'deleted',
        ])->assertOk()->assertSee('Purged 0 log records.');

        Event::assertDispatched(GlobalRefresh::class);
    });

    it('reports a workspace it cannot purge the run logs of', function () {
        Event::fake([GlobalRefresh::class]);

        $this->mock(ProjectsService::class)
            ->shouldReceive('loadProjectWorkspace')->once()->with('/tmp/nope')
            ->andThrow(new WorkspaceNotFound('/tmp/nope'));

        LaborForestServer::tool(PurgeWorkflowLogsTool::class, ['path' => '/tmp/nope', 'workflow' => 'up'])
            ->assertHasErrors([(new WorkspaceNotFound('/tmp/nope'))->getMessage()]);

        Event::assertNotDispatched(GlobalRefresh::class);
    });

    it('reports run log files it cannot delete', function () {
        Event::fake([GlobalRefresh::class]);

        mcpWorkspaceIsResolved();

        $this->mock(WorkflowService::class)
            ->shouldReceive('purgeWorkflowLogs')->once()->andThrow(new WorkflowLogsNotDeleted('up'));

        LaborForestServer::tool(PurgeWorkflowLogsTool::class, [
            'path' => '/tmp/repo-feature',
            'workflow' => 'up',
        ])->assertHasErrors(['Failed to delete the log records of workflow [up].']);

        Event::assertNotDispatched(GlobalRefresh::class);
    });

    it('overrides the status of the workspace at the path', function (WorkspaceStatus $status) {
        Event::fake([GlobalRefresh::class]);

        mcpWorkspaceIsResolved()
            ->shouldReceive('updateProjectWorkspaceStatus')->once()
            ->with('/tmp/repo-feature', $status);

        // The trailing slash is trimmed before the workspace is looked up
        LaborForestServer::tool(OverrideWorkspaceStatusTool::class, [
            'path' => '/tmp/repo-feature/',
            'status' => $status->value,
        ])->assertOk()->assertSee('success');

        Event::assertDispatched(GlobalRefresh::class);
    })->with('overridable statuses');

    it('clears the error status a failed run leaves behind, which is what it is for', function () {
        Event::fake([GlobalRefresh::class]);

        mcpWorkspaceIsResolved(WorkspaceStatus::ERROR)
            ->shouldReceive('updateProjectWorkspaceStatus')->once()
            ->with('/tmp/repo-feature', WorkspaceStatus::READY);

        LaborForestServer::tool(OverrideWorkspaceStatusTool::class, [
            'path' => '/tmp/repo-feature',
            'status' => WorkspaceStatus::READY->value,
        ])->assertOk()->assertSee('success');

        Event::assertDispatched(GlobalRefresh::class);
    });

    it('refuses a workspace whose run is still in flight', function (WorkspaceStatus $status) {
        Event::fake([GlobalRefresh::class]);

        // the finishing job writes its own final status, which would overwrite the override
        mcpWorkspaceIsResolved($status)
            ->shouldNotReceive('updateProjectWorkspaceStatus');

        LaborForestServer::tool(OverrideWorkspaceStatusTool::class, [
            'path' => '/tmp/repo-feature',
            'status' => WorkspaceStatus::READY->value,
        ])->assertHasErrors([
            "Workspace at path '/tmp/repo-feature' has a workflow run in flight and is '{$status->value}'. Override the status from the app once the run has finished.",
        ]);

        Event::assertNotDispatched(GlobalRefresh::class);
    })->with('in flight statuses');

    it('reports a workspace it cannot override the status of', function () {
        Event::fake([GlobalRefresh::class]);

        $this->mock(ProjectsService::class)
            ->shouldReceive('loadProjectWorkspace')->once()->with('/tmp/nope')
            ->andThrow(new WorkspaceNotFound('/tmp/nope'));

        LaborForestServer::tool(OverrideWorkspaceStatusTool::class, [
            'path' => '/tmp/nope',
            'status' => WorkspaceStatus::READY->value,
        ])->assertHasErrors([(new WorkspaceNotFound('/tmp/nope'))->getMessage()]);

        Event::assertNotDispatched(GlobalRefresh::class);
    });

    it('reports a status file it cannot write', function () {
        Event::fake([GlobalRefresh::class]);

        mcpWorkspaceIsResolved()
            ->shouldReceive('updateProjectWorkspaceStatus')->once()
            ->andThrow(new ProjectDirectoryNotFound('/tmp/repo-feature'));

        LaborForestServer::tool(OverrideWorkspaceStatusTool::class, [
            'path' => '/tmp/repo-feature',
            'status' => WorkspaceStatus::SUSPENDED->value,
        ])->assertHasErrors([(new ProjectDirectoryNotFound('/tmp/repo-feature'))->getMessage()]);

        Event::assertNotDispatched(GlobalRefresh::class);
    });
});

dataset('overridable statuses', [
    'ready' => [WorkspaceStatus::READY],
    'suspended' => [WorkspaceStatus::SUSPENDED],
]);

dataset('in flight statuses', [
    'pending' => [WorkspaceStatus::PENDING],
    'working' => [WorkspaceStatus::WORKING],
]);

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

/**
 * Answer File::isFile() for the given workflow path alone, so nothing is written to disk.
 *
 * Paths outside the fixture tree still resolve, because Laravel's own translation files are
 * loaded when a validation message is rendered.
 */
function mcpWorkflowFileExists(string $workflowPath): void
{
    File::partialMock()
        ->shouldReceive('isFile')
        ->andReturnUsing(fn (string $path) => str_starts_with($path, '/tmp/')
            ? $path === $workflowPath
            : is_file($path));
}

/**
 * Answer the workspace lookup ResolvesWorkspace performs for the standard fixture workspace.
 */
function mcpWorkspaceIsResolved(WorkspaceStatus $status = WorkspaceStatus::READY): MockInterface
{
    return test()->mock(ProjectsService::class, function (MockInterface $mock) use ($status) {
        $mock->shouldReceive('loadProjectWorkspace')->once()->with('/tmp/repo-feature')
            ->andReturn(componentWorkspaceData('/tmp/repo-feature', status: $status));
        $mock->shouldReceive('loadProjectFromWorkspace')->once()->with('/tmp/repo-feature')
            ->andReturn(componentProjectData('11111111-1111-1111-1111-111111111111', '/tmp/repo'));
    });
}

/**
 * Settings with MCP in the writable mode a mutating tool needs to be registered at all.
 *
 * Read-only is the default a fresh settings file carries, so a test exercising a write tool has to
 * say so rather than inherit it.
 */
function mcpWritableSettings(?SettingsData $settings = null): SettingsData
{
    $settings ??= SettingsData::defaults();

    $settings->mcp_read_only = false;

    return $settings;
}
