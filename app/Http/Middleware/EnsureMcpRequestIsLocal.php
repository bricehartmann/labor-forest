<?php

namespace App\Http\Middleware;

use App\Enums\McpEndpoint;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse requests that reached the endpoint through a browser pointed somewhere else.
 *
 * The MCP specification asks local servers to validate `Origin` for exactly one attack: DNS
 * rebinding. An attacker's page cannot POST JSON to the loopback port directly, because the
 * content type forces a CORS preflight nothing here answers — but rebinding the attacker's own
 * name to 127.0.0.1 makes the request same-origin and no preflight happens at all.
 *
 * `Host` is the check that catches it, since the request still names the attacker's domain.
 * `Origin` is checked too, because it costs nothing and covers a client that sends one.
 *
 * An absent `Origin` is allowed: no MCP client sends the header. `php artisan mcp:inspector` does,
 * being a browser application, which is why a loopback origin passes rather than only an absent one.
 */
class EnsureMcpRequestIsLocal
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isLoopbackHost($request->getHost())) {
            abort(403, 'The MCP server only answers requests addressed to the loopback interface.');
        }

        $origin = $request->headers->get('Origin');

        if (filled($origin) && ! $this->isLoopbackHost((string) parse_url($origin, PHP_URL_HOST))) {
            abort(403, 'The MCP server does not answer cross-origin requests.');
        }

        return $next($request);
    }

    /**
     * Whether the given host names this machine.
     */
    protected function isLoopbackHost(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), array_map(
            static fn (string $loopback): string => strtolower(trim($loopback, '[]')),
            McpEndpoint::LOOPBACK_HOSTS,
        ), true);
    }
}
