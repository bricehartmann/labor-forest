<?php

namespace App\Providers;

use App\Enums\HostEnvKey;
use App\Http\Middleware\AllowOnlyMcpRequests;
use App\Services\McpService;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View;
use Native\Desktop\Http\Middleware\PreventRegularBrowserAccess;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /**
         * Scoped, so the read-only answer memoized on the service is shared by every MCP tool
         * deciding whether to register itself during a single request.
         */
        $this->app->scoped(McpService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->hardenNativeDatabaseConnection();

        $this->allowExternalAccessToMcpServer();

        RateLimiter::for('mcp', fn (Request $request): Limit => Limit::perMinute(120)->by($request->ip()));

        FilamentView::registerRenderHook(
            PanelsRenderHook::TOPBAR_END,
            fn (): View => view('filament.global.refresh'),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn (): View => view('filament.global.workflow-notifications'),
        );
    }

    /**
     * Let MCP clients reach the server NativePHP would otherwise shut them out of.
     *
     * The MCP server runs as a child process of the native runtime, so it boots with
     * NATIVEPHP_RUNNING set and NativePHP pushes PreventRegularBrowserAccess onto the global
     * middleware while it registers. That middleware answers 403 to every request without the
     * runtime's own cookie or secret header, which no MCP client will ever send.
     *
     * The marker is read with getenv() rather than through config, because a packaged build boots
     * from a cached config and never parses .env — the variable only ever exists in the process
     * environment. Swapping the middleware here rather than in bootstrap/app.php keeps NativePHP's
     * own guard in place for the app window, which is the browser it means to keep out.
     *
     * The guard is replaced rather than removed. `artisan serve` serves the whole application, and
     * the Filament panel sits at the root path with no authentication of its own, so dropping
     * PreventRegularBrowserAccess and putting nothing back would answer the entire app UI on the
     * MCP port. AllowOnlyMcpRequests narrows the process to the one route it exists to serve.
     */
    private function allowExternalAccessToMcpServer(): void
    {
        if (! getenv(HostEnvKey::MCP_SERVER->value)) {
            return;
        }

        $kernel = app(Kernel::class);

        $kernel->setGlobalMiddleware(array_values(array_map(
            fn (string $middleware): string => $middleware === PreventRegularBrowserAccess::class
                ? AllowOnlyMcpRequests::class
                : $middleware,
            $kernel->getGlobalMiddleware(),
        )));
    }

    /**
     * Re-apply the SQLite concurrency pragmas NativePHP drops when it rewrites the
     * `nativephp` connection at boot.
     *
     * Without `transaction_mode = IMMEDIATE` the queue's `pop()` transaction opens
     * DEFERRED, so its SELECT takes a read lock and the following `reserved_at` UPDATE
     * has to upgrade to a write lock. SQLite refuses that upgrade with an immediate,
     * non-retryable `database is locked` when another process holds the write lock —
     * `busy_timeout` cannot help there. Acquiring the write lock up front makes the
     * timeout effective again.
     */
    private function hardenNativeDatabaseConnection(): void
    {
        if (! config('nativephp-internal.running') || ! config()->has('database.connections.nativephp')) {
            return;
        }

        config([
            'database.connections.nativephp' => [
                ...config('database.connections.nativephp'),
                'busy_timeout' => 5000,
                'journal_mode' => 'WAL',
                'synchronous' => 'NORMAL',
                'transaction_mode' => 'IMMEDIATE',
            ],
        ]);

        DB::purge('nativephp');
    }
}
