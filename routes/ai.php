<?php

use App\Enums\McpEndpoint;
use App\Mcp\Servers\LaborForestServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web(McpEndpoint::LABORFOREST->path(), LaborForestServer::class);
