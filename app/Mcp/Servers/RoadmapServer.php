<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetEpic;
use App\Mcp\Tools\ListEpics;
use App\Mcp\Tools\ListSquads;
use Laravel\Mcp\Server;

class RoadmapServer extends Server
{
    protected string $name = 'Project Roadmap';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Read-only access to the Project Roadmap app: the epics (projects) a team
        is working on, the squads that own them, their statuses, quarter plans,
        and the Jira / Jira Product Discovery links attached to each one.

        Everything is scoped to the authenticated user's current team.

        Typical flow:
        1. `list-squads` to learn the squad ids and the team's status vocabulary.
        2. `list-epics` (optionally filtered by squad, status or quarter) for a
           summary of each epic including its Jira and JPD keys.
        3. `get-epic` for the full picture of one epic: description, quarter
           plans, engineers, pauses and the comment thread.

        Dates are ISO 8601. Quarters are written `YYYY-Qn`, e.g. `2026-Q3`.
        Nothing here writes data.
    MARKDOWN;

    protected array $tools = [
        ListSquads::class,
        ListEpics::class,
        GetEpic::class,
    ];
}
