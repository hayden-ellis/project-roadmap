<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddComment;
use App\Mcp\Tools\EditComment;
use App\Mcp\Tools\GetEpic;
use App\Mcp\Tools\ListEpics;
use App\Mcp\Tools\ListSquads;
use App\Mcp\Tools\SetEpicStatus;
use App\Mcp\Tools\UpdateEpic;
use Laravel\Mcp\Server;

class RoadmapServer extends Server
{
    protected string $name = 'Project Roadmap';

    protected string $version = '1.1.0';

    protected string $instructions = <<<'MARKDOWN'
        Access to the Project Roadmap app: the epics (projects) a team is
        working on, the squads that own them, their statuses, quarter plans,
        and the Jira / Jira Product Discovery links attached to each one.

        Everything is scoped to the authenticated user's current team.

        Typical flow:
        1. `list-squads` to learn the squad ids and the team's status vocabulary.
        2. `list-epics` (optionally filtered by squad, status or quarter) for a
           summary of each epic including its Jira and JPD keys.
        3. `get-epic` for the full picture of one epic: description, quarter
           plans, engineers, pauses and the comment thread.

        With a write token you can also change things, and every change is
        made as the token's owner, notifying their teammates as the app would:
        - `add-comment` posts a comment or reply on an epic.
        - `edit-comment` rewrites a comment the token owner wrote.
        - `update-epic` changes a title, description, priority or Jira / JPD link.
        - `set-epic-status` moves an epic to another column; pause columns
          need a `reason`.
        Read-only tokens get a clear error from these tools.

        Dates are ISO 8601. Quarters are written `YYYY-Qn`, e.g. `2026-Q3`.
    MARKDOWN;

    protected array $tools = [
        ListSquads::class,
        ListEpics::class,
        GetEpic::class,
        AddComment::class,
        EditComment::class,
        UpdateEpic::class,
        SetEpicStatus::class,
    ];
}
