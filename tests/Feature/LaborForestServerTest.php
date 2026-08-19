<?php

use App\Mcp\Servers\LaborForestServer;
use Laravel\Mcp\Server\Contracts\Transport;

it('reports the application version to connecting clients', function () {
    config(['nativephp.version' => '1.2.3']);

    $server = new LaborForestServer($this->mock(Transport::class));

    expect($server->createContext()->implementation->version)->toBe('1.2.3');
});

it('falls back to the development version when the app version is unset', function () {
    config(['nativephp.version' => null]);

    $server = new LaborForestServer($this->mock(Transport::class));

    expect($server->createContext()->implementation->version)->toBe('main');
});
