<?php

namespace App\Services;

use App\Enums\ChildProcessAlias;
use App\Enums\HostEnvKey;
use App\Exceptions\InvalidSettingsFile;
use App\Exceptions\McpServerNotEnabled;
use App\Exceptions\McpServerNotStopped;
use Native\Desktop\Facades\ChildProcess;

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
            '--host=127.0.0.1',
            '--port='.$settings->mcp_port,
        ], alias: ChildProcessAlias::MCP_SERVER->value, env: [
            HostEnvKey::MCP_SERVER->value => '1',
            HostEnvKey::PHP_BINARY->value => PHP_BINARY,
        ], persistent: true);
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
