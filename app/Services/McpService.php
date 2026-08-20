<?php

namespace App\Services;

use App\Data\McpServerHealthData;
use App\Data\SettingsData;
use App\Enums\ChildProcessAlias;
use App\Enums\HostEnvKey;
use App\Enums\McpEndpoint;
use App\Enums\McpServerStatus;
use App\Exceptions\InvalidSettingsFile;
use App\Exceptions\McpServerNotEnabled;
use App\Exceptions\McpServerNotStopped;
use App\Exceptions\McpServerUnhealthy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Mcp\Enums\ProtocolVersion;
use Native\Desktop\Facades\ChildProcess;
use stdClass;

class McpService
{
    /**
     * Number of times the stopped process is polled before the restart is abandoned.
     */
    protected const int STOP_POLL_ATTEMPTS = 30;

    /**
     * Milliseconds between two polls of a stopped process.
     */
    protected const int STOP_POLL_INTERVAL_MS = 100;

    /**
     * Seconds a health check waits for the endpoint to answer.
     *
     * Deliberately small. The check runs inside a Livewire request of the app window, and both that
     * window and the MCP server are served by a single-worker `php -S` — ServeCommand reads
     * PHP_CLI_SERVER_WORKERS and defaults it to 1. A check aimed at the window's own port could
     * therefore only ever time out, so checkMcpServer() refuses that case up front and this budget
     * is the backstop for the ways it could still happen.
     */
    protected const int HEALTH_TIMEOUT_SECONDS = 3;

    /**
     * Seconds a health check waits for the connection itself. A refused loopback connection comes
     * back immediately, so this only bounds a port that accepts and then goes quiet.
     */
    protected const int HEALTH_CONNECT_TIMEOUT_SECONDS = 1;

    /**
     * The memoized answer of isReadOnly().
     */
    protected ?bool $readOnly = null;

    /**
     * Start the MCP server, unless it is already running.
     *
     * @throws McpServerNotEnabled when MCP is switched off in the settings
     * @throws InvalidSettingsFile
     */
    public function startMcpServer(): void
    {
        $settings = app(SettingsService::class)->loadSettings();

        if (! $settings->mcp_enabled) {
            throw new McpServerNotEnabled;
        }

        if (ChildProcess::get(ChildProcessAlias::MCP_SERVER->value)) {
            return;
        }

        if (blank($settings->mcp_token)) {
            $this->regenerateMcpToken();
        }

        /**
         * `artisan serve` does not serve anything itself: it spawns a second `php -S` process and,
         * without --no-reload, hands it only the variables named in ServeCommand::$passthroughVariables
         * — every other key of $_ENV is passed as false, which tells Symfony to drop it. None of the
         * NATIVEPHP_* variables are on that list, so the process actually answering MCP requests would
         * boot with NATIVEPHP_RUNNING unset: no user_home or extras disk, no rewritten storage path,
         * and no nativephp database connection. --no-reload passes $_ENV through untouched. The only
         * behavior given up is restarting on a change to .env, which a background server has no use
         * for — the persistent watchdog already brings it back after a crash.
         *
         * Passing $_ENV through untouched is also what lets a PHP_BINARY set by whatever launched the
         * application reach the second process, and Symfony's finder prefers that variable over the
         * PHP_BINARY constant. Composer sets it, so `composer native:dev` would otherwise serve MCP
         * from the developer's own PHP. Naming the binary here is what NativePHP does for its own
         * server, and it keeps a PHP installation from being a requirement of running the app.
         */
        ChildProcess::artisan([
            'serve',
            '--no-reload',
            '--host='.McpEndpoint::HOST,
            '--port='.$settings->mcp_port,
        ], alias: ChildProcessAlias::MCP_SERVER->value, env: [
            HostEnvKey::MCP_SERVER->value => '1',
            HostEnvKey::PHP_BINARY->value => PHP_BINARY,
        ], persistent: true);
    }

    /**
     * Whether the server publishes only the tools that change nothing.
     *
     * Memoized, because every tool asks on every request while the server builds its primitive
     * list, and each answer would otherwise re-read and re-validate the settings file. The service
     * is bound scoped for exactly this reason.
     *
     * A settings file that cannot be read answers false, so an unreadable file is never mistaken
     * for a mode the user asked for.
     */
    public function isReadOnly(): bool
    {
        return $this->readOnly ??= rescue(
            fn (): bool => app(SettingsService::class)->loadSettings()->mcp_read_only,
            false,
            report: false,
        );
    }

    /**
     * Write a fresh bearer token to the settings file and return it.
     *
     * Generated here rather than shipped as a default, so the token cannot be the same on two
     * machines and so a settings file written before the token existed grows one on first start.
     *
     * @throws InvalidSettingsFile
     */
    public function regenerateMcpToken(): string
    {
        $settingsService = app(SettingsService::class);

        $settings = $settingsService->loadSettings();
        $settings->mcp_token = Str::random(SettingsData::MCP_TOKEN_LENGTH);

        $settingsService->saveSettings($settings);

        return $settings->mcp_token;
    }

    /**
     * Complete an MCP initialize handshake against the endpoint the given port serves.
     *
     * The distinctions this draws are the ones a client cannot draw for the user. Claude Code maps
     * both 401 and 403 to "needs authentication" and shows its green check for nothing else, so a
     * NativePHP browser guard still sitting in front of the route is indistinguishable there from a
     * server that genuinely wants a token. Here it is named.
     *
     * The MCP bearer token is sent, so a correctly configured server answers HEALTHY rather than
     * FORBIDDEN. No NativePHP cookie or secret header is sent, deliberately: a 403 from the browser
     * guard is the signal this check exists to surface, and authenticating that far would hide it.
     *
     * @throws McpServerUnhealthy when the endpoint does not answer, or does not answer as an MCP server
     * @throws InvalidSettingsFile
     */
    public function checkMcpServer(int $port): McpServerHealthData
    {
        $url = McpEndpoint::LABORFOREST->url($port);

        /**
         * The app window's own server has one worker and is busy serving this very request, so a
         * request back to it could only ever time out. Refuse before spending the budget.
         */
        if ($port === request()->getPort()) {
            throw new McpServerUnhealthy(McpServerStatus::APP_PORT, $url);
        }

        $token = app(SettingsService::class)->loadSettings()->mcp_token;

        try {
            $response = Http::withHeaders(['Accept' => 'application/json, text/event-stream'])
                ->when(filled($token), fn (PendingRequest $request): PendingRequest => $request->withToken($token))
                ->connectTimeout(static::HEALTH_CONNECT_TIMEOUT_SECONDS)
                ->timeout(static::HEALTH_TIMEOUT_SECONDS)
                ->post($url, [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'initialize',
                    'params' => [
                        'protocolVersion' => ProtocolVersion::LATEST->value,
                        'capabilities' => new stdClass,
                        'clientInfo' => [
                            'name' => config('app.name'),
                            'version' => config('nativephp.version') ?? 'main',
                        ],
                    ],
                ]);
        } catch (ConnectionException) {
            throw new McpServerUnhealthy(McpServerStatus::UNREACHABLE, $url);
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new McpServerUnhealthy(McpServerStatus::FORBIDDEN, $url, $response->status());
        }

        if (! $response->successful()) {
            throw new McpServerUnhealthy(McpServerStatus::FAILED, $url, $response->status());
        }

        $result = $this->decodeInitializeResult($response->body());

        if ($result === null) {
            throw new McpServerUnhealthy(McpServerStatus::FOREIGN, $url, $response->status());
        }

        return new McpServerHealthData(
            status: McpServerStatus::HEALTHY,
            url: $url,
            server_name: $result['serverInfo']['name'],
            server_version: (string) ($result['serverInfo']['version'] ?? ''),
            protocol_version: $result['protocolVersion'],
        );
    }

    public function stopMcpServer(): void
    {
        ChildProcess::get(ChildProcessAlias::MCP_SERVER->value)?->stop();
    }

    /**
     * Stop the MCP server and start it again on the port the settings now name.
     *
     * ChildProcess::restart() replays the argv the process was started with, so a changed port would
     * be dropped on the floor. The runtime hands back the existing process for as long as the alias
     * is registered, and it only deregisters it from the exit handler, so the new one cannot be
     * started until the old one is gone.
     *
     * @throws McpServerNotEnabled when MCP is switched off in the settings
     * @throws McpServerNotStopped when the running server does not exit
     * @throws InvalidSettingsFile
     */
    public function restartMcpServer(): void
    {
        $this->stopMcpServer();
        $this->awaitStoppedMcpServer();
        $this->startMcpServer();
    }

    /**
     * The `result` member of a JSON-RPC initialize reply, or null when the body is not one.
     *
     * The reply arrives as bare JSON, because HttpTransport only answers as an event stream when a
     * primitive registered a stream callback and `initialize` never does. The Accept header still
     * names both, since the specification requires it and the package reads it to pick JSON. A
     * `data:` frame is unwrapped anyway, so a server that streams regardless is understood rather
     * than reported as something else entirely.
     *
     * @return array<string, mixed>|null
     */
    protected function decodeInitializeResult(string $body): ?array
    {
        $json = trim($body);

        if (str_starts_with($json, 'data:')) {
            $json = trim(substr($json, strlen('data:')));
        }

        $payload = json_decode($json, associative: true);

        if (! is_array($payload) || ($payload['jsonrpc'] ?? null) !== '2.0') {
            return null;
        }

        $result = $payload['result'] ?? null;

        if (! is_array($result)
            || ! is_string($result['protocolVersion'] ?? null)
            || ! is_string($result['serverInfo']['name'] ?? null)) {
            return null;
        }

        return $result;
    }

    /**
     * Wait for the runtime to deregister the stopped server.
     *
     * @throws McpServerNotStopped when the running server does not exit
     */
    protected function awaitStoppedMcpServer(): void
    {
        for ($attempt = 0; $attempt < static::STOP_POLL_ATTEMPTS; $attempt++) {
            if (! ChildProcess::get(ChildProcessAlias::MCP_SERVER->value)) {
                return;
            }

            usleep(static::STOP_POLL_INTERVAL_MS * 1000);
        }

        throw new McpServerNotStopped(static::STOP_POLL_ATTEMPTS);
    }
}
