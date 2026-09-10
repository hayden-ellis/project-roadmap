<?php

namespace App\Actions\Comments;

use App\Models\Epic;
use App\Models\EpicComment;
use App\Models\User;
use App\Notifications\EpicCommented;
use App\Notifications\EpicMentioned;
use App\Support\Mentions;
use Illuminate\Support\Facades\Notification;

/**
 * Save a comment and tell the right people. The board's flyout and the epic
 * page both come through here, so a comment means the same thing wherever
 * it was typed.
 *
 * Who hears: anyone named with an @ gets the mention. Everyone else already
 * in the conversation gets the plainer "commented on". The author gets
 * nothing, even when they name themselves.
 */
final class PostComment
{
    public function handle(Epic $epic, User $author, string $body, ?EpicComment $parent = null): EpicComment
    {
        $comment = EpicComment::create([
            'epic_id' => $epic->id,
            'user_id' => $author->id,
            // Threading is one level deep: replying to a reply re-roots
            // onto the top-level comment.
            'parent_id' => $parent ? ($parent->parent_id ?? $parent->id) : null,
            'body' => $body,
        ]);

        $mentioned = Mentions::resolve($epic->team, $body)
            ->reject(fn (User $user) => $user->id === $author->id);

        $comment->mentions()->sync($mentioned->pluck('id'));

        Notification::send($mentioned, new EpicMentioned($comment));

        Notification::send(
            $epic->participants()
                ->reject(fn (User $user) => $user->id === $author->id)
                ->reject(fn (User $user) => $mentioned->contains('id', $user->id)),
            new EpicCommented($comment),
        );

        return $comment;
    }
}
