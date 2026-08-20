<?php

namespace App\Concerns\Mcp;

use App\Services\McpService;

/**
 * Drop a tool from the server while MCP is in read-only mode.
 *
 * Applied to every tool that changes something outside the server process — the settings and
 * projects files, the git worktrees on disk, the run logs — and to the launch tools, which change
 * nothing in LaborForest but do spawn a user-configured command.
 *
 * The answer is memoized on McpService, because every tool asks on every request while the server
 * builds its primitive list. A settings file that cannot be read registers the tool: answering the
 * other way would meet an unreadable file with a silently shortened tool list, which reads to a
 * client as a server that simply does less, and read-only is a mode the user opts into.
 */
trait RegistersWhenWritable
{
    /**
     * Determine if the tool should be registered.
     */
    public function shouldRegister(): bool
    {
        return ! app(McpService::class)->isReadOnly();
    }
}
