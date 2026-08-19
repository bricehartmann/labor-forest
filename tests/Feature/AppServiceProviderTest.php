<?php

use App\Enums\HostEnvKey;
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

    it('keeps the browser guard in every other process', function () {
        (new AppServiceProvider(app()))->boot();

        expect($this->kernel->getGlobalMiddleware())->toContain(PreventRegularBrowserAccess::class);
    });
});
