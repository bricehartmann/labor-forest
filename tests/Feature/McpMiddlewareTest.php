<?php

use App\Data\SettingsData;
use App\Enums\McpEndpoint;
use App\Http\Middleware\AllowOnlyMcpRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Mcp\Enums\ProtocolVersion;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->disk = Storage::fake('user_home');
    $this->token = Str::random(SettingsData::MCP_TOKEN_LENGTH);
    $this->url = McpEndpoint::LABORFOREST->path();

    $this->writeSettings = function (array $overrides = []) {
        $this->disk->put('.laborforest/settings.yaml', settingsYaml($overrides));
    };

    ($this->writeSettings)(['mcp_token' => $this->token]);

    /**
     * A well-formed handshake, so a request that gets past the middleware answers 200 rather than
     * failing further in and hiding which guard let it through.
     */
    $this->initialize = fn (array $headers = [], ?string $origin = null) => $this->postJson(($origin ?? '').$this->url, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => ProtocolVersion::LATEST->value,
            'capabilities' => new stdClass,
            'clientInfo' => ['name' => 'Pest', 'version' => '1.0.0'],
        ],
    ], [
        'Accept' => 'application/json, text/event-stream',
        ...$headers,
    ]);
});

describe('EnsureMcpTokenIsValid', function () {
    it('answers the handshake when the presented token is the one the settings file holds', function () {
        ($this->initialize)(['Authorization' => 'Bearer '.$this->token])
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'LaborForest');
    });

    it('refuses a request carrying no token at all', function () {
        ($this->initialize)()->assertUnauthorized();
    });

    it('refuses a token that is not the stored one', function () {
        ($this->initialize)(['Authorization' => 'Bearer '.Str::random(SettingsData::MCP_TOKEN_LENGTH)])
            ->assertUnauthorized();
    });

    it('fails closed when the settings file holds no token', function () {
        ($this->writeSettings)(['mcp_token' => null]);

        ($this->initialize)(['Authorization' => 'Bearer '.$this->token])->assertUnauthorized();
    });

    it('fails closed when the settings file cannot be read', function () {
        $this->disk->put('.laborforest/settings.yaml', "just a string\n");

        ($this->initialize)(['Authorization' => 'Bearer '.$this->token])->assertUnauthorized();
    });

    it('answers 401 rather than 403, so a client reports it as authentication', function () {
        ($this->initialize)()->assertStatus(401);
    });
});

describe('EnsureMcpRequestIsLocal', function () {
    it('refuses a request addressed to a host that is not the loopback interface', function () {
        // How a DNS-rebound request gives itself away: same-origin to the browser, so no preflight
        // is asked for, but the attacker's own name still travels in the Host header.
        ($this->initialize)(
            ['Authorization' => 'Bearer '.$this->token],
            origin: 'http://rebound.evil.test',
        )->assertForbidden();
    });

    it('answers a request addressed to the loopback interface by name', function () {
        ($this->initialize)(
            ['Authorization' => 'Bearer '.$this->token],
            origin: 'http://localhost',
        )->assertOk();
    });

    it('refuses a cross-origin request', function () {
        ($this->initialize)([
            'Origin' => 'https://evil.test',
            'Authorization' => 'Bearer '.$this->token,
        ])->assertForbidden();
    });

    it('allows a loopback origin, which is what the mcp inspector sends', function (string $origin) {
        ($this->initialize)([
            'Origin' => $origin,
            'Authorization' => 'Bearer '.$this->token,
        ])->assertOk();
    })->with([
        'inspector' => 'http://localhost:6274',
        'loopback address' => 'http://127.0.0.1:6274',
    ]);

    it('refuses before the token is even considered, so a rebound request learns nothing', function () {
        ($this->initialize)(['Origin' => 'https://evil.test'])->assertForbidden();
    });
});

describe('AllowOnlyMcpRequests', function () {
    $handle = fn (string $path) => (new AllowOnlyMcpRequests)->handle(
        Request::create($path, 'POST'),
        fn () => response('reached'),
    );

    it('passes the mcp endpoint through', function () use ($handle) {
        expect($handle(McpEndpoint::LABORFOREST->path())->getContent())->toBe('reached');
    });

    it('hides every other route the artisan server would otherwise answer', function (string $path) use ($handle) {
        expect(fn () => $handle($path))
            ->toThrow(NotFoundHttpException::class);
    })->with([
        // the Filament panel is registered at the root path with no authentication of its own
        'the panel' => '/',
        'a panel page' => '/settings',
        'a livewire endpoint' => '/livewire/update',
        'the nativephp api' => '/_native/api/booted',
    ]);

    it('answers a hidden route as missing rather than as refused', function () use ($handle) {
        try {
            $handle('/');
        } catch (HttpException $e) {
            expect($e->getStatusCode())->toBe(404);

            return;
        }

        $this->fail('Expected the request to be refused.');
    });
});
