<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sanctum's guard also accepts a browser session, and a session counts as
 * having every ability. Machine endpoints such as the MCP server should only
 * open for a real bearer token, so this runs after auth:sanctum and turns
 * away anyone who arrived on a session cookie.
 */
class RequireAccessToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            abort(401, 'A bearer token is required.');
        }

        return $next($request);
    }
}
