<?php

namespace Tests\Fakes;

use App\Services\McpService;

/**
 * An McpService that gives up on a stopping server, and on an occupied port, almost immediately.
 *
 * The real polls wait up to three seconds for the runtime to deregister the alias and half a second
 * for a stopped server to let go of its socket, which is the right budget for a process being killed
 * but far too long to spend proving that a wait gives up.
 */
final class ImpatientMcpService extends McpService
{
    protected const int STOP_POLL_ATTEMPTS = 2;

    protected const int PORT_POLL_ATTEMPTS = 2;

    protected const int STOP_POLL_INTERVAL_MS = 1;
}
