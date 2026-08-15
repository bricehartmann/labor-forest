<?php

namespace App\Listeners;

use App\Enums\WindowId;
use App\Services\CliToolsService;
use Native\Desktop\Events\App\OpenedFromURL;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as NativeWindow;

/**
 * Pick up a request from the `lf` script while the app is already running.
 *
 * A cold launch is handled in NativeAppServiceProvider instead: its deeplink fires before the PHP
 * server is listening, and NativePHP's notifyLaravel() swallows the failure.
 */
class RunPendingCliCommand
{
    /**
     * The event carries the deeplink URL, but it is only a wake trigger — the request itself
     * travels through ~/.laborforest/pending.yaml.
     */
    public function handle(OpenedFromURL $event): void
    {
        $target = app(CliToolsService::class)->runPendingCommand();

        if ($target === null) {
            return;
        }

        $this->navigateTo($target);
    }

    /**
     * Show the window on the given page, opening it if the app is running without one.
     *
     * The window is addressed by id rather than through Window::current(), which asks Electron for
     * the *focused* window and dies on `null.id` whenever nothing has focus. Re-opening an id that
     * already exists is how NativePHP exposes show() + focus().
     *
     * Overridden in tests, which have no Electron runtime to talk to.
     */
    protected function navigateTo(string $url): void
    {
        $window = collect(Window::all())
            ->first(fn (NativeWindow $window): bool => $window->getId() === WindowId::MAIN->value);

        if ($window === null) {
            Window::open(WindowId::MAIN->value)->url($url)->maximized();

            return;
        }

        $window->url($url);

        Window::open(WindowId::MAIN->value);
    }
}
