<?php

namespace App\Providers;

use App\Listeners\RunPendingCliCommand;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View;
use Native\Desktop\Events\App\ApplicationBooted;
use Native\Desktop\Events\App\OpenedFromURL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->hardenNativeDatabaseConnection();

        $this->listenForCliCommands();

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
     * Pick up requests from the `lf` script.
     *
     * Registered by hand rather than by discovery: discovery keys off a single event-class type
     * hint on handle(), and this listener answers to two. OpenedFromURL covers a running app;
     * ApplicationBooted covers a cold launch, whose deeplink fires before the PHP server is up
     * and is swallowed by NativePHP's notifyLaravel().
     */
    private function listenForCliCommands(): void
    {
        Event::listen(OpenedFromURL::class, RunPendingCliCommand::class);
        Event::listen(ApplicationBooted::class, RunPendingCliCommand::class);
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
