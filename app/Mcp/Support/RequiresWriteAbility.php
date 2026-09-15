<?php

namespace App\Mcp\Support;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Write tools sit behind the same route as the read tools, so the route's
 * `mcp:read` check is not enough on its own. Each write tool asks for the
 * `mcp:write` ability, which `php artisan mcp:token --write` grants and a
 * plain read token never carries.
 */
trait RequiresWriteAbility
{
    protected function writeDenied(Request $request): ?Response
    {
        if ($request->user()?->tokenCan('mcp:write')) {
            return null;
        }

        return Response::error(
            'This token is read-only. Mint one with write access using `php artisan mcp:token you@example.com --write`.'
        );
    }
}
