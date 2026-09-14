<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\ResolvesTeam;
use App\Models\Squad;
use App\Models\Status;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListSquads extends Tool
{
    use ResolvesTeam;

    protected string $description = <<<'MARKDOWN'
        List the squads on the current team, with their active engineers and a
        count of unfinished epics each one has planned. Also returns the team's
        status vocabulary (in board order) so you know which `status` values
        `list-epics` accepts. Call this first.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $team = $this->team($request);

        if (! $team) {
            return Response::error('The authenticated user has no current team.');
        }

        $squads = $team->squads()
            ->ordered()
            ->with(['engineers' => fn ($q) => $q->active()->ordered()])
            ->get()
            ->map(fn (Squad $squad) => [
                'id' => $squad->id,
                'name' => $squad->name,
                'engineers' => $squad->engineers->map(fn ($engineer) => [
                    'id' => $engineer->id,
                    'name' => $engineer->name,
                    'title' => $engineer->title,
                ])->values()->all(),
                'unfinished_epics' => $squad->epics()->unfinished()->count(),
            ])
            ->values()
            ->all();

        $statuses = $team->statuses()
            ->ordered()
            ->get()
            ->map(fn (Status $status) => [
                'name' => $status->name,
                'description' => $status->description,
                'is_default' => $status->is_default,
                'is_complete' => $status->is_complete,
                'requires_reason' => $status->requires_reason,
            ])
            ->values()
            ->all();

        return Response::json([
            'team' => $team->name,
            'squads' => $squads,
            'statuses' => $statuses,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
