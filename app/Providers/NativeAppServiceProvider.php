<?php

namespace App\Providers;

use App\Enums\QueryParameter;
use App\Enums\WindowId;
use App\Exceptions\McpServerPortInUse;
use App\Filament\Pages\Dashboard;
use App\Services\CliToolsService;
use App\Services\McpService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as NativeWindow;
use Throwable;

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

        $mcpFailure = null;

        if ($mcpEnabled) {
            rescue(
                fn () => app(McpService::class)->startMcpServer(),
                function (Throwable $throwable) use (&$mcpFailure): void {
                    $mcpFailure = $throwable;
                },
            );
        }

        /**
         * A cold `lf` launch is served here rather than from a deeplink event: macOS fires open-url
         * before the PHP server is listening, and NativePHP's notifyLaravel() drops the failure.
         * Running it before the window opens means the window loads the target page directly,
         * instead of showing the dashboard and then replacing it.
         */
        $target = rescue(fn (): ?string => app(CliToolsService::class)->runPendingCommand());

        /**
         * A CLI request is what the user asked for by name, so it keeps the window. Only when there
         * is none does an occupied MCP port get to choose the landing page: the alternative is an
         * app whose MCP server is silently dead, which the user next meets as a failing client.
         */
        $target ??= $this->mcpFailureUrl($mcpFailure);

        $window = Window::open(WindowId::MAIN->value)
            ->maximized();

        if ($target !== null) {
            $this->navigateTo($window, $target);
        }
    }

    /**
     * Show the freshly opened window on the given page.
     *
     * Overridden in tests, which have no Electron runtime for the window to report the change to.
     */
    protected function navigateTo(NativeWindow $window, string $url): void
    {
        $window->url($url);
    }

    /**
     * The dashboard, carrying the reason the MCP server did not start, or null when it did.
     *
     * Only a port that is already answering is reported. It is the one failure the user has to act
     * on themselves — every other way a start can fail is either transient or already visible on the
     * settings screen — and the message names the occupant the probe found.
     */
    protected function mcpFailureUrl(?Throwable $failure): ?string
    {
        if (! $failure instanceof McpServerPortInUse) {
            return null;
        }

        return rescue(fn (): string => Dashboard::getUrl([
            QueryParameter::ERROR->value => 'The MCP server could not be started',
            QueryParameter::BODY->value => $failure->getMessage(),
        ]));
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
