<?php

namespace App\Http\Middleware;

use App\Enums\McpEndpoint;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve nothing but the MCP endpoint from the MCP server process.
 *
 * `artisan serve` serves the whole application, and the Filament panel is registered at the root
 * path with no authentication guard of its own. The MCP process has NativePHP's
 * PreventRegularBrowserAccess removed so clients can reach the endpoint at all, which without this
 * would leave the entire app window UI answering on the MCP port.
 *
 * A 404 rather than a 403, because nothing else is meant to exist there.
 */
class AllowOnlyMcpRequests
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is(ltrim(McpEndpoint::LABORFOREST->path(), '/'))) {
            abort(404);
        }

        return $next($request);
    }
}
