<?php

use App\Data\SettingsData;
use App\Enums\ChildProcessAlias;
use App\Enums\HostEnvKey;
use App\Enums\McpEndpoint;
use App\Enums\McpServerStatus;
use App\Exceptions\McpServerNotEnabled;
use App\Exceptions\McpServerNotStopped;
use App\Exceptions\McpServerUnhealthy;
use App\Services\McpService;
use App\Services\SettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Mcp\Enums\ProtocolVersion;
use Native\Desktop\Contracts\ChildProcess as ChildProcessContract;
use Symfony\Component\Yaml\Yaml;
use Tests\Fakes\ImpatientMcpService;

beforeEach(function () {
    $this->disk = Storage::fake('user_home');
    $this->path = '.laborforest/settings.yaml';
    $this->alias = ChildProcessAlias::MCP_SERVER->value;

    $this->writeSettings = function (array $overrides = []) {
        $this->disk->put($this->path, settingsYaml($overrides));
    };

    $this->mcp = new McpService;
});

describe('startMcpServer', function () {
    it('serves the mcp routes from a persistent artisan process on the configured port', function () {
        ($this->writeSettings)(['mcp_enabled' => true, 'mcp_port' => 9876]);

        $this->mock(ChildProcessContract::class, function ($mock) {
            $mock->shouldReceive('get')->once()->with($this->alias)->andReturnNull();

            $mock->shouldReceive('artisan')->once()->withArgs(function (
                array $cmd,
                string $alias,
                ?array $env,
                ?bool $persistent,
            ) {
                expect($cmd)->toBe(['serve', '--no-reload', '--host=127.0.0.1', '--port=9876'])
                    ->and($alias)->toBe($this->alias)
                    ->and($env)->toBe([
                        HostEnvKey::MCP_SERVER->value => '1',
                        HostEnvKey::PHP_BINARY->value => PHP_BINARY,
                    ])
                    ->and($persistent)->toBeTrue();

                return true;
            })->andReturnSelf();
        });

        $this->mcp->startMcpServer();
    });

    it('names the php binary the application itself runs on, so a leaked one cannot win', function () {
        ($this->writeSettings)(['mcp_enabled' => true]);

        $this->mock(ChildProcessContract::class, function ($mock) {
            $mock->shouldReceive('get')->andReturnNull();
            $mock->shouldReceive('artisan')->once()->withArgs(
                fn (array $cmd, string $alias, ?array $env) => $env[HostEnvKey::PHP_BINARY->value] === PHP_BINARY,
            )->andReturnSelf();
        });

        $this->mcp->startMcpServer();
    });

    it('passes --no-reload so the served process keeps the native environment', function () {
        ($this->writeSettings)(['mcp_enabled' => true]);

        $this->mock(ChildProcessContract::class, function ($mock) {
            $mock->shouldReceive('get')->andReturnNull();
            $mock->shouldReceive('artisan')->once()->withArgs(
                fn (array $cmd) => in_array('--no-reload', $cmd, true),
            )->andReturnSelf();
        });

        $this->mcp->startMcpServer();
    });

    it('leaves an already running server alone', function () {
        ($this->writeSettings)(['mcp_enabled' => true]);

        $this->mock(ChildProcessContract::class, function ($mock) {
            $mock->shouldReceive('get')->once()->with($this->alias)->andReturnSelf();
            $mock->shouldReceive('artisan')->never();
        });

        $this->mcp->startMcpServer();
    });

    it('throws when mcp is disabled in the settings', function () {
        ($this->writeSettings)(['mcp_enabled' => false]);

        $this->mock(ChildProcessContract::class, function ($mock) {
            $mock->shouldReceive('get')->never();
            $mock->shouldReceive('artisan')->never();
        });

        expect(fn () => $this->mcp->startMcpServer())->toThrow(McpServerNotEnabled::class);
    });
});

describe('stopMcpServer', function () {
    it('stops the process registered under the mcp alias', function () {
        $this->mock(ChildProcessContract::class, function ($mock) {
            $mock->shouldReceive('get')->once()->with($this->alias)->andReturnSelf();
            $mock->shouldReceive('stop')->once();
        });

        $this->mcp->stopMcpServer();
    });

    it('does nothing when no server is running', function () {
        $this->mock(ChildProcessContract::class, function ($mock) {
            $mock->shouldReceive('get')->once()->with($this->alias)->andReturnNull();
            $mock->shouldReceive('stop')->never();
        });

        $this->mcp->stopMcpServer();
    });
});

describe('restartMcpServer', function () {
    it('stops the running server before starting one on the new port', function () {
        ($this->writeSettings)(['mcp_enabled' => true, 'mcp_port' => 9001]);

        $calls = [];

        $this->mock(ChildProcessContract::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('get')
                ->times(3)
                ->with($this->alias)
                ->andReturn($mock, null, null);

            $mock->shouldReceive('stop')->once()->andReturnUsing(function () use (&$calls) {
                $calls[] = 'stop';
            });

            $mock->shouldReceive('artisan')->once()->andReturnUsing(function (array $cmd) use (&$calls, $mock) {
                $calls[] = 'artisan';

                expect($cmd)->toContain('--port=9001');

                return $mock;
            });
        });

        $this->mcp->restartMcpServer();

        expect($calls)->toBe(['stop', 'artisan']);
    });

    it('leaves the server stopped when a restart finds mcp switched off', function () {
        // reachable from the settings screen, which decides what to do from the values it is saving
        // rather than from the file the service reads back
        ($this->writeSettings)(['mcp_enabled' => false]);

        $calls = [];

        $this->mock(ChildProcessContract::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('get')->twice()->with($this->alias)->andReturn($mock, null);

            $mock->shouldReceive('stop')->once()->andReturnUsing(function () use (&$calls) {
                $calls[] = 'stop';
            });

            $mock->shouldReceive('artisan')->never();
        });

        expect(fn () => $this->mcp->restartMcpServer())->toThrow(McpServerNotEnabled::class);

        expect($calls)->toBe(['stop']);
    });

    it('throws rather than starting a second server when the old one will not exit', function () {
        ($this->writeSettings)(['mcp_enabled' => true]);

        $this->mock(ChildProcessContract::class, function ($mock) {
            $mock->shouldReceive('get')->with($this->alias)->andReturnSelf();
            $mock->shouldReceive('stop')->once();
            $mock->shouldReceive('artisan')->never();
        });

        expect(fn () => (new ImpatientMcpService)->restartMcpServer())
            ->toThrow(McpServerNotStopped::class, 'still running after 2 attempts');
    });
});

describe('mcp token', function () {
    it('writes a token on first start, since a settings file predating the server has none', function () {
        ($this->writeSettings)(['mcp_enabled' => true, 'mcp_token' => null]);

        $this->mock(ChildProcessContract::class, function ($mock) {
            $mock->shouldReceive('get')->andReturnNull();
            $mock->shouldReceive('artisan')->andReturnSelf();
        });

        $this->mcp->startMcpServer();

        expect(Yaml::parse($this->disk->get($this->path))['mcp_token'])
            ->toBeString()
            ->toHaveLength(SettingsData::MCP_TOKEN_LENGTH);
    });

    it('leaves a token that already exists alone, so registered clients keep working', function () {
        $token = Str::random(SettingsData::MCP_TOKEN_LENGTH);

        ($this->writeSettings)(['mcp_enabled' => true, 'mcp_token' => $token]);

        $this->mock(ChildProcessContract::class, function ($mock) {
            $mock->shouldReceive('get')->andReturnNull();
            $mock->shouldReceive('artisan')->andReturnSelf();
        });

        $this->mcp->startMcpServer();

        expect(Yaml::parse($this->disk->get($this->path))['mcp_token'])->toBe($token);
    });

    it('replaces the token when asked, and hands back the new one', function () {
        $token = Str::random(SettingsData::MCP_TOKEN_LENGTH);

        ($this->writeSettings)(['mcp_token' => $token]);

        $regenerated = $this->mcp->regenerateMcpToken();

        expect($regenerated)->toHaveLength(SettingsData::MCP_TOKEN_LENGTH)
            ->not->toBe($token)
            ->and(Yaml::parse($this->disk->get($this->path))['mcp_token'])->toBe($regenerated);
    });

    it('writes the settings file so only its owner can read the token', function () {
        ($this->writeSettings)();

        $this->mcp->regenerateMcpToken();

        expect($this->disk->getVisibility($this->path))->toBe('private');
    });
});

describe('isReadOnly', function () {
    it('answers what the settings file says', function (bool $readOnly) {
        ($this->writeSettings)(['mcp_read_only' => $readOnly]);

        expect($this->mcp->isReadOnly())->toBe($readOnly);
    })->with(['on' => true, 'off' => false]);

    it('reads the settings file once, however many tools ask', function () {
        ($this->writeSettings)(['mcp_read_only' => true]);

        $this->mock(SettingsService::class)
            ->shouldReceive('loadSettings')->once()
            ->andReturn(new SettingsData(mcp_read_only: true));

        expect($this->mcp->isReadOnly())->toBeTrue()
            ->and($this->mcp->isReadOnly())->toBeTrue()
            ->and($this->mcp->isReadOnly())->toBeTrue();
    });

    it('publishes every tool when the settings file cannot be read', function () {
        $this->disk->put($this->path, "just a string\n");

        expect($this->mcp->isReadOnly())->toBeFalse();
    });
});

describe('checkMcpServer', function () {
    beforeEach(function () {
        $this->port = 9876;
        $this->url = McpEndpoint::LABORFOREST->url($this->port);

        // The status, not the message, is what the settings page branches on.
        $this->statusOf = fn (callable $call) => rescue(
            $call,
            fn (McpServerUnhealthy $th) => $th->status,
            report: false,
        );
    });

    it('reports the server that completed the handshake', function () {
        Http::fake([$this->url => Http::response(mcpInitializeReplyPayload())]);

        expect($this->mcp->checkMcpServer($this->port))
            ->status->toBe(McpServerStatus::HEALTHY)
            ->url->toBe($this->url)
            ->server_name->toBe('LaborForest')
            ->server_version->toBe('1.2.3')
            ->protocol_version->toBe('2025-11-25');
    });

    it('presents the bearer token, so a guarded server answers rather than challenges', function () {
        $token = Str::random(SettingsData::MCP_TOKEN_LENGTH);

        ($this->writeSettings)(['mcp_token' => $token]);

        Http::fake([$this->url => Http::response(mcpInitializeReplyPayload())]);

        $this->mcp->checkMcpServer($this->port);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer '.$token));
    });

    it('sends no token when the settings file holds none, rather than sending an empty one', function () {
        ($this->writeSettings)(['mcp_token' => null]);

        Http::fake([$this->url => Http::response(mcpInitializeReplyPayload())]);

        $this->mcp->checkMcpServer($this->port);

        Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
    });

    it('sends a json-rpc initialize accepting both json and an event stream', function () {
        Http::fake([$this->url => Http::response(mcpInitializeReplyPayload())]);

        $this->mcp->checkMcpServer($this->port);

        Http::assertSent(fn (Request $request) => $request->url() === $this->url
            && $request->method() === 'POST'
            && $request->hasHeader('Accept', 'application/json, text/event-stream')
            && $request['jsonrpc'] === '2.0'
            && $request['method'] === 'initialize'
            && $request['params']['protocolVersion'] === ProtocolVersion::LATEST->value);
    });

    it('reads a reply framed as a server-sent event', function () {
        Http::fake([$this->url => Http::response('data: '.json_encode(mcpInitializeReplyPayload())."\n\n")]);

        expect($this->mcp->checkMcpServer($this->port)->status)->toBe(McpServerStatus::HEALTHY);
    });

    it('reports the server unreachable when the connection is refused', function () {
        Http::fake([$this->url => fn () => throw new ConnectionException('Connection refused')]);

        expect(($this->statusOf)(fn () => $this->mcp->checkMcpServer($this->port)))
            ->toBe(McpServerStatus::UNREACHABLE);
    });

    it('names the browser guard when the endpoint refuses the request', function (int $status) {
        Http::fake([$this->url => Http::response('', $status)]);

        expect(($this->statusOf)(fn () => $this->mcp->checkMcpServer($this->port)))
            ->toBe(McpServerStatus::FORBIDDEN);
    })->with(['unauthorized' => 401, 'forbidden' => 403]);

    it('reports a foreign server when the reply is not a handshake', function (mixed $body) {
        Http::fake([$this->url => Http::response($body)]);

        expect(($this->statusOf)(fn () => $this->mcp->checkMcpServer($this->port)))
            ->toBe(McpServerStatus::FOREIGN);
    })->with([
        'html' => '<html>Nope</html>',
        'json-rpc error' => [['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32601, 'message' => 'Method not found']]],
        'empty object' => [[]],
        'result without serverInfo' => [['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-11-25']]],
    ]);

    it('reports a failure for any other status', function (int $status) {
        Http::fake([$this->url => Http::response('', $status)]);

        expect(($this->statusOf)(fn () => $this->mcp->checkMcpServer($this->port)))
            ->toBe(McpServerStatus::FAILED);
    })->with(['not found' => 404, 'server error' => 500]);

    it('never probes the port the app window is served on', function () {
        expect(($this->statusOf)(fn () => $this->mcp->checkMcpServer(request()->getPort())))
            ->toBe(McpServerStatus::APP_PORT);

        Http::assertNothingSent();
    });
});
