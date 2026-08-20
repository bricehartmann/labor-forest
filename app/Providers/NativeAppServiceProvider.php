<?php

namespace App\Providers;

use App\Enums\WindowId;
use App\Services\CliToolsService;
use App\Services\McpService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        /**
         * The cache is a file store inside the app's own storage directory, so entries written by a
         * previous version are still on disk after an upgrade. Emptying it here binds a cached value
         * to a single run of the app, and nothing can be read back into code that no longer
         * understands its shape. TTLs still govern within a launch.
         *
         * This runs from boot() rather than a listener on Native\Desktop\Events\App\ApplicationBooted
         * because that event is delivered over HTTP, and on a cold launch NativePHP's notifyLaravel()
         * swallows the failure — the same reason the pending CLI command is drained here.
         */
        Cache::flush();

        $settingsService = app(SettingsService::class);

        /**
         * Heal the settings.yaml file with any newly added keys.
         */
        rescue(fn () => $settingsService->syncSettingsFile());

        /**
         * Start the MCP server, if enabled in settings. The enabled check is made here rather than
         * left to the exception McpService throws, so a deliberately disabled server is not reported
         * as a failure on every launch. A failure that does happen must not reach the caller: boot()
         * has not opened the window yet, so an unusable MCP port would leave the user with a running
         * app and nothing on screen.
         */
        $mcpEnabled = rescue(fn () => $settingsService->loadSettings()->mcp_enabled, false);

        if ($mcpEnabled) {
            rescue(fn () => app(McpService::class)->startMcpServer());
        }

        /**
         * A cold `lf` launch is served here rather than from a deeplink event: macOS fires open-url
         * before the PHP server is listening, and NativePHP's notifyLaravel() drops the failure.
         * Running it before the window opens means the window loads the target page directly,
         * instead of showing the dashboard and then replacing it.
         */
        $target = rescue(fn (): ?string => app(CliToolsService::class)->runPendingCommand());

        $window = Window::open(WindowId::MAIN->value)
            ->maximized();

        if ($target !== null) {
            $window->url($target);
        }
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [
        ];
    }
}
