<?php

namespace Tests\Fakes;

use App\Services\McpService;

/**
 * An McpService that gives up on a stopping server almost immediately.
 *
 * The real poll waits up to three seconds for the runtime to deregister the alias, which is the
 * right budget for a process being killed but far too long to spend proving that the wait gives up.
 */
final class ImpatientMcpService extends McpService
{
    protected const int STOP_POLL_ATTEMPTS = 2;

    protected const int STOP_POLL_INTERVAL_MS = 1;
}
