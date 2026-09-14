<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\EpicPayload;
use App\Mcp\Support\ResolvesTeam;
use App\Models\Epic;
use App\Support\Quarter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListEpics extends Tool
{
    use ResolvesTeam;

    protected string $description = <<<'MARKDOWN'
        List the epics (projects) on the current team as summary rows: status,
        category, priority, dates, Jira epic key and URL, Jira Product Discovery
        idea key and URL, the squads planned on it, per-quarter planned and
        delivered points, and the pause reason if it is currently paused.

        Filter by `squad_id` (from `list-squads`), `status` (a status name from
        `list-squads`, case-insensitive), or `quarter` (`YYYY-Qn`). Complete
        epics are left out unless `include_complete` is true. Use `get-epic`
        for the description, engineers and comments of one epic.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $team = $this->team($request);

        if (! $team) {
            return Response::error('The authenticated user has no current team.');
        }

        $validated = $request->validate([
            'squad_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
            'quarter' => ['nullable', 'string', 'regex:/^\d{4}-Q[1-4]$/'],
            'include_complete' => ['nullable', 'boolean'],
        ]);

        $query = $team->epics()
            ->with(['status', 'category', 'quarterPlans.squad', 'pauses'])
            ->onBoard();

        if (! ($validated['include_complete'] ?? false)) {
            $query->unfinished();
        }

        if ($squadId = $validated['squad_id'] ?? null) {
            $squad = $team->squads()->find($squadId);

            if (! $squad) {
                return Response::error("Squad [{$squadId}] was not found on this team. Call list-squads for valid ids.");
            }

            $query->whereHas('quarterPlans', fn ($q) => $q->where('squad_id', $squad->id));
        }

        if ($statusName = $validated['status'] ?? null) {
            $status = $team->statuses()->whereRaw('lower(name) = ?', [mb_strtolower($statusName)])->first();

            if (! $status) {
                $known = $team->statuses()->ordered()->pluck('name')->implode(', ');

                return Response::error("Status [{$statusName}] was not found on this team. Known statuses: {$known}.");
            }

            $query->where('status_id', $status->id);
        }

        if ($quarterKey = $validated['quarter'] ?? null) {
            $query->forQuarter(Quarter::parse($quarterKey));
        }

        $epics = $query->get();

        return Response::json([
            'team' => $team->name,
            'count' => $epics->count(),
            'filters' => array_filter([
                'squad_id' => $validated['squad_id'] ?? null,
                'status' => $validated['status'] ?? null,
                'quarter' => $validated['quarter'] ?? null,
                'include_complete' => (bool) ($validated['include_complete'] ?? false),
            ], fn ($value) => $value !== null && $value !== false),
            'epics' => $epics->map(fn (Epic $epic) => EpicPayload::summary($epic))->values()->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'squad_id' => $schema->integer()
                ->description('Only epics planned on this squad. Get ids from list-squads.'),
            'status' => $schema->string()
                ->description('Only epics in this status, by name (case-insensitive). Get names from list-squads.'),
            'quarter' => $schema->string()
                ->pattern('^\d{4}-Q[1-4]$')
                ->description('Only epics with a quarter plan in this quarter, e.g. 2026-Q3.'),
            'include_complete' => $schema->boolean()
                ->description('Include epics whose status is marked complete. Defaults to false.'),
        ];
    }
}
