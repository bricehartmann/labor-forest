<?php

namespace App\Exceptions;

use Exception;

class McpServerNotStopped extends Exception
{
    public function __construct(int $attempts)
    {
        parent::__construct("The MCP server was still running after {$attempts} attempts to stop it.");
    }
}
