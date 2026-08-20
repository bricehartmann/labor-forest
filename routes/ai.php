<?php

use App\Enums\McpEndpoint;
use App\Http\Middleware\EnsureMcpRequestIsLocal;
use App\Http\Middleware\EnsureMcpTokenIsValid;
use App\Mcp\Servers\LaborForestServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web(McpEndpoint::LABORFOREST->path(), LaborForestServer::class)
    ->middleware([
        EnsureMcpRequestIsLocal::class,
        EnsureMcpTokenIsValid::class,
        'throttle:mcp',
    ]);
