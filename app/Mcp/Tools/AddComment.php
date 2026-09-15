<?php

namespace App\Mcp\Tools;

use App\Actions\Comments\PostComment;
use App\Mcp\Support\RequiresWriteAbility;
use App\Mcp\Support\ResolvesTeam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AddComment extends Tool
{
    use RequiresWriteAbility, ResolvesTeam;

    protected string $description = <<<'MARKDOWN'
        Post a comment on an epic, or reply to an existing comment by passing
        its `parent_id`. The comment is published under the token owner's
        name and notifies everyone in the thread, exactly as if they typed it
        in the app, so only post what the user asked you to post. Mention a
        teammate with `@Their Name` to notify them directly. Needs a token
        with write access.
    MARKDOWN;

    public function handle(Request $request, PostComment $postComment): Response
    {
        if ($denied = $this->writeDenied($request)) {
            return $denied;
        }

        $team = $this->team($request);

        if (! $team) {
            return Response::error('The authenticated user has no current team.');
        }

        $validated = $request->validate([
            'epic_id' => ['required', 'integer'],
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        $epic = $team->epics()->find($validated['epic_id']);

        if (! $epic || ! $request->user()->can('update', $epic)) {
            return Response::error("Epic [{$validated['epic_id']}] was not found on this team.");
        }

        $parent = null;

        if ($parentId = $validated['parent_id'] ?? null) {
            $parent = $epic->comments()->find($parentId);

            if (! $parent) {
                return Response::error("Comment [{$parentId}] was not found on epic [{$epic->id}].");
            }
        }

        $comment = $postComment->handle($epic, $request->user(), $validated['body'], $parent);

        return Response::json([
            'id' => $comment->id,
            'epic_id' => $epic->id,
            'parent_id' => $comment->parent_id,
            'author' => $request->user()->name,
            'body' => $comment->body,
            'created_at' => $comment->created_at?->toIso8601String(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'epic_id' => $schema->integer()
                ->required()
                ->description('The epic to comment on, as returned by list-epics.'),
            'body' => $schema->string()
                ->required()
                ->max(5000)
                ->description('The comment text. Plain text; `@Name` mentions a teammate.'),
            'parent_id' => $schema->integer()
                ->description('Reply to this comment id (from get-epic) instead of starting a new thread.'),
        ];
    }
}
