<?php

namespace App\Policies;

use App\Models\EpicComment;
use App\Models\User;

/**
 * A comment is the one object here with a personal author. Every other gate
 * in this app is "same team can do anything", but letting teammates rewrite
 * or delete each other's words is different in kind from re-dragging a card
 * -- so editing and deleting stay with the author. Creating a comment needs
 * no gate of its own: it happens through the epic, guarded by EpicPolicy.
 */
class EpicCommentPolicy
{
    public function update(User $user, EpicComment $comment): bool
    {
        return $this->owns($user, $comment);
    }

    public function delete(User $user, EpicComment $comment): bool
    {
        return $this->owns($user, $comment);
    }

    /**
     * The team check rides along so a stale currentTeam can never authorize
     * a cross-team write, even for the original author.
     */
    private function owns(User $user, EpicComment $comment): bool
    {
        return $user->id === $comment->user_id
            && $user->currentTeam->id === $comment->epic->team_id;
    }
}
