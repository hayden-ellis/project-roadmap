<?php

use App\Http\Middleware\RequireAccessToken;
use App\Mcp\Servers\RoadmapServer;
use Laravel\Mcp\Facades\Mcp;

// Read-only roadmap access for Claude Code. Authenticate with a Sanctum
// bearer token minted by `php artisan mcp:token you@example.com`; the token's
// user decides which team's data comes back. Sessions are refused on purpose.
Mcp::web('/mcp', RoadmapServer::class)
    ->middleware([
        'throttle:60,1',
        'auth:sanctum',
        RequireAccessToken::class,
        'abilities:mcp:read',
    ]);
