<?php

namespace App\Exceptions;

use App\Enums\McpServerStatus;
use Exception;

/**
 * The port the MCP server was asked to serve is already answering.
 *
 * Separate from McpServerUnhealthy, which reports what a probe found: this one is thrown instead of
 * spawning a server that could only fail to bind, and carries the probe's finding so the user is
 * told which kind of occupant is in the way.
 */
class McpServerPortInUse extends Exception
{
    public function __construct(
        public readonly McpServerStatus $status,
        public readonly string $url,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($status->message($url, $httpStatus));
    }
}
