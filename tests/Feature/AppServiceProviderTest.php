<?php

use App\Enums\HostEnvKey;
use App\Http\Middleware\AllowOnlyMcpRequests;
use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Native\Desktop\Http\Middleware\PreventRegularBrowserAccess;

beforeEach(function () {
    $this->kernel = app(Kernel::class);

    $this->originalMiddleware = $this->kernel->getGlobalMiddleware();

    // NativePHP pushes this while it registers, which only happens inside the native runtime.
    $this->kernel->setGlobalMiddleware([...$this->originalMiddleware, PreventRegularBrowserAccess::class]);
});

afterEach(function () {
    $this->kernel->setGlobalMiddleware($this->originalMiddleware);

    putenv(HostEnvKey::MCP_SERVER->value);
});

describe('boot', function () {
    it('lets mcp clients through by dropping the browser guard in the mcp server process', function () {
        putenv(HostEnvKey::MCP_SERVER->value.'=1');

        (new AppServiceProvider(app()))->boot();

        expect($this->kernel->getGlobalMiddleware())->not->toContain(PreventRegularBrowserAccess::class);
    });

    it('puts the mcp guard in the place the browser guard held', function () {
        putenv(HostEnvKey::MCP_SERVER->value.'=1');

        (new AppServiceProvider(app()))->boot();

        $middleware = $this->kernel->getGlobalMiddleware();

        expect($middleware)->toContain(AllowOnlyMcpRequests::class)
            // swapped in place, so the guard runs exactly where NativePHP's own guard would have
            ->and(array_search(AllowOnlyMcpRequests::class, $middleware, true))
            ->toBe(array_search(PreventRegularBrowserAccess::class, [...$this->originalMiddleware, PreventRegularBrowserAccess::class], true));
    });

    it('keeps the browser guard in every other process', function () {
        (new AppServiceProvider(app()))->boot();

        expect($this->kernel->getGlobalMiddleware())->toContain(PreventRegularBrowserAccess::class)
            ->and($this->kernel->getGlobalMiddleware())->not->toContain(AllowOnlyMcpRequests::class);
    });
});
