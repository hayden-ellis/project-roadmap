<?php

namespace App\Mcp\Tools;

use App\Actions\Comments\UpdateComment;
use App\Mcp\Support\RequiresWriteAbility;
use App\Mcp\Support\ResolvesTeam;
use App\Models\EpicComment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class EditComment extends Tool
{
    use RequiresWriteAbility, ResolvesTeam;

    protected string $description = <<<'MARKDOWN'
        Replace the text of a comment the token owner wrote. Other people's
        comments cannot be edited. Use the comment `id` from get-epic. Needs
        a token with write access.
    MARKDOWN;

    public function handle(Request $request, UpdateComment $updateComment): Response
    {
        if ($denied = $this->writeDenied($request)) {
            return $denied;
        }

        $team = $this->team($request);

        if (! $team) {
            return Response::error('The authenticated user has no current team.');
        }

        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $comment = EpicComment::whereHas('epic', fn ($q) => $q->where('team_id', $team->id))
            ->find($validated['id']);

        if (! $comment) {
            return Response::error("Comment [{$validated['id']}] was not found on this team.");
        }

        if (! $request->user()->can('update', $comment)) {
            return Response::error("Comment [{$comment->id}] was written by someone else and can only be edited by its author.");
        }

        $comment = $updateComment->handle($comment, $validated['body']);

        return Response::json([
            'id' => $comment->id,
            'epic_id' => $comment->epic_id,
            'body' => $comment->body,
            'updated_at' => $comment->updated_at?->toIso8601String(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->required()
                ->description('The comment id, as returned by get-epic.'),
            'body' => $schema->string()
                ->required()
                ->max(5000)
                ->description('The full replacement text.'),
        ];
    }
}
