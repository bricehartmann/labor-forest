<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum McpEndpoint: string
{
    /**
     * The loopback interface every MCP server binds to.
     *
     * Named here rather than at the two call sites, so the host `artisan serve` is told to listen on
     * and the host the settings page advertises cannot drift apart.
     */
    public const string HOST = '127.0.0.1';

    case LABORFOREST = 'mcp/laborforest';

    /**
     * The route path, exactly as routes/ai.php registers it.
     */
    public function path(): string
    {
        return '/'.$this->value;
    }

    /**
     * The URL an MCP client connects to when the server is listening on the given port.
     */
    public function url(int $port): string
    {
        return 'http://'.self::HOST.':'.$port.$this->path();
    }

    /**
     * The name a client registers the server under, which is the last segment of the route.
     */
    public function clientName(): string
    {
        return Str::afterLast($this->value, '/');
    }

    /**
     * The one-liner that registers this endpoint with Claude Code.
     */
    public function claudeAddCommand(int $port): string
    {
        return 'claude mcp add --transport http '.$this->clientName().' --scope user '.$this->url($port);
    }
}
