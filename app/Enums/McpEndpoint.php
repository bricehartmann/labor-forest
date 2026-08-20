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

    /**
     * The host names a request may legitimately arrive under.
     *
     * `Host` is what a DNS-rebound request gives itself away with: the browser resolves the
     * attacker's own name to 127.0.0.1, which makes the request same-origin and skips the CORS
     * preflight, but the name still travels in the header.
     *
     * @var list<string>
     */
    public const array LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '[::1]', '::1'];

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
     *
     * The token is passed as a header rather than in the URL, because Claude Code stores the whole
     * command in its own config and a URL is the part that ends up in logs and error messages.
     */
    public function claudeAddCommand(int $port, ?string $token = null): string
    {
        $command = 'claude mcp add --transport http '.$this->clientName().' --scope user '.$this->url($port);

        if (blank($token)) {
            return $command;
        }

        return $command.' --header "Authorization: Bearer '.$token.'"';
    }
}
