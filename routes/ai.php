<?php

use App\Mcp\Servers\LaborForestServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/laborforest', LaborForestServer::class);
