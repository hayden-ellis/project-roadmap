<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\EpicPayload;
use App\Mcp\Support\ResolvesTeam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetEpic extends Tool
{
    use ResolvesTeam;

    protected string $description = <<<'MARKDOWN'
        Everything about one epic: the summary fields from `list-epics` plus its
        description, importance/urgency quadrant, the engineers allocated to it
        and for how many weeks, its pause history, and the full comment thread
        with authors and timestamps. Use the `id` from `list-epics`.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $team = $this->team($request);

        if (! $team) {
            return Response::error('The authenticated user has no current team.');
        }

        $validated = $request->validate([
            'id' => ['required', 'integer'],
        ]);

        $epic = $team->epics()
            ->with([
                'status',
                'category',
                'quarterPlans.squad',
                'pauses',
                'allocations.engineer.squad',
                'comments.user',
                'comments.replies.user',
            ])
            ->find($validated['id']);

        if (! $epic) {
            return Response::error("Epic [{$validated['id']}] was not found on this team.");
        }

        return Response::json(EpicPayload::detail($epic));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->required()
                ->description('The epic id, as returned by list-epics.'),
        ];
    }
}
