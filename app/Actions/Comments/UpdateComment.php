<?php

namespace App\Actions\Comments;

use App\Models\EpicComment;
use App\Models\User;
use App\Notifications\EpicMentioned;
use App\Support\Mentions;
use Illuminate\Support\Facades\Notification;

/**
 * Change a comment's words. Only people the edit newly names hear about
 * it -- an edit is not a fresh comment, so participants are left alone and
 * anyone already mentioned has already been told.
 */
final class UpdateComment
{
    public function handle(EpicComment $comment, string $body): EpicComment
    {
        $already = $comment->mentions()->pluck('users.id');

        $comment->update(['body' => $body]);

        $mentioned = Mentions::resolve($comment->epic->team, $body)
            ->reject(fn (User $user) => $user->id === $comment->user_id);

        $comment->mentions()->sync($mentioned->pluck('id'));

        Notification::send(
            $mentioned->reject(fn (User $user) => $already->contains($user->id)),
            new EpicMentioned($comment),
        );

        return $comment;
    }
}
