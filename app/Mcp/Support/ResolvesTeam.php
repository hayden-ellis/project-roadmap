<?php

namespace App\Mcp\Support;

use App\Models\Team;
use Laravel\Mcp\Request;

trait ResolvesTeam
{
    /**
     * Every tool answers for the token owner's current team, exactly as the
     * web UI would. A user with no team gets nothing rather than everything.
     */
    protected function team(Request $request): ?Team
    {
        return $request->user()?->currentTeam;
    }
}
