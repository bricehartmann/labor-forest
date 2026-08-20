<?php

use App\Data\SettingsData;
use App\Enums\WorkflowStatus;
use App\Exceptions\InvalidProjectsFile;
use App\Exceptions\InvalidSettingsFile;
use App\Mcp\Resources\ProjectResource;
use App\Mcp\Resources\ProjectsResource;
use App\Mcp\Resources\SettingsResource;
use App\Mcp\Resources\WorkflowLogResource;
use App\Mcp\Resources\WorkflowLogsResource;
use App\Mcp\Servers\LaborForestServer;
use App\Services\ProjectsService;
use App\Services\SettingsService;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Resource;
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

describe('resources', function () {
    it('lists the fixed-uri resources separately from the templated ones', function () {
        $context = (new LaborForestServer($this->mock(Transport::class)))->createContext();

        expect($context->resources()->map(fn (Resource $resource) => $resource->name())->values()->all())
            ->toBe(['settings', 'projects'])
            ->and($context->resources()->map(fn (Resource $resource) => $resource->uri())->values()->all())
            ->toBe(['laborforest://settings', 'laborforest://projects'])
            ->and($context->resourceTemplates()->map(fn (Resource $resource) => $resource->name())->values()->all())
            ->toBe(['project', 'workspaces', 'workspace', 'workflows', 'workflow', 'workflow-logs', 'workflow-log'])
            ->and($context->resourceTemplates()->map(fn (Resource $resource) => (string) $resource->uriTemplate())->values()->all())
            ->toBe([
                'laborforest://projects/{uuid}',
                'laborforest://projects/{uuid}/workspaces',
                'laborforest://projects/{uuid}/workspaces/{slugKebab}',
                'laborforest://projects/{uuid}/workspaces/{slugKebab}/workflows',
                'laborforest://projects/{uuid}/workspaces/{slugKebab}/workflows/{name}',
                'laborforest://projects/{uuid}/workspaces/{slugKebab}/workflows/{name}/logs',
                'laborforest://projects/{uuid}/workspaces/{slugKebab}/workflows/{name}/logs/{id}',
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
            ->assertSee(mcpJson([$beta->toMcpResource(), $alpha->toMcpResource()]));
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

describe('run log resources', function () {
    beforeEach(function () {
        $this->uuid = '44444444-4444-4444-4444-444444444444';
        $this->workspace = workflowWorkspaceData(fixtureWorkspacePath('repo-logs'));

        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjects')
                ->andReturn(collect([componentProjectData($this->uuid, '/tmp/repo')]));

            $mock->shouldReceive('loadProjectWorkspaces')->andReturn(collect([$this->workspace]));
        });
    });

    it('lists only the runs of the named workflow, without their steps', function () {
        LaborForestServer::resource(WorkflowLogsResource::class, [
            'uuid' => $this->uuid,
            'slugKebab' => 'repo-logs',
            'name' => 'down',
        ])
            ->assertOk()
            ->assertSee(mcpJson([
                componentRunLogSummaryData(
                    id: '20240102T000000Z_repo-logs_down',
                    name: 'down',
                    parent: '20240101T000000Z_repo-logs_up',
                    timestamp: 1704153600,
                    status: WorkflowStatus::FAILED,
                    exception: 'Step [Stop the containers] failed.',
                )->toMcpResource(),
            ]))
            ->assertDontSee('boom')
            ->assertDontSee('docker compose down');
    });

    it('lists no run for a workflow that has never run', function () {
        LaborForestServer::resource(WorkflowLogsResource::class, [
            'uuid' => $this->uuid,
            'slugKebab' => 'repo-logs',
            'name' => 'nothing-ran',
        ])
            ->assertOk()
            ->assertSee('[]');
    });

    it('reads a single run with the output of every step', function () {
        LaborForestServer::resource(WorkflowLogResource::class, [
            'uuid' => $this->uuid,
            'slugKebab' => 'repo-logs',
            'name' => 'down',
            'id' => '20240102T000000Z_repo-logs_down',
        ])
            ->assertOk()
            ->assertSee('Stop the containers')
            ->assertSee('docker compose down')
            ->assertSee('boom');
    });

    it('reports a run log id that matches no run', function () {
        LaborForestServer::resource(WorkflowLogResource::class, [
            'uuid' => $this->uuid,
            'slugKebab' => 'repo-logs',
            'name' => 'down',
            'id' => '20240108T000000Z_repo-logs_down',
        ])
            ->assertHasErrors(['Failed to load workflow log.']);
    });

    it('refuses a run log addressed under another workflow name', function () {
        LaborForestServer::resource(WorkflowLogResource::class, [
            'uuid' => $this->uuid,
            'slugKebab' => 'repo-logs',
            'name' => 'up',
            'id' => '20240102T000000Z_repo-logs_down',
        ])
            ->assertHasErrors(['Failed to load workflow log.']);
    });
});

/**
 * The JSON exactly as App\Concerns\Mcp\RespondsWithJson encodes it.
 *
 * @param  array<array-key, mixed>  $payload
 */
function mcpJson(array $payload): string
{
    return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
