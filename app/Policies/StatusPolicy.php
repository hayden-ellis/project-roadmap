<?php

namespace App\Policies;

use App\Models\Status;
use App\Models\User;

class StatusPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->currentTeam !== null;
    }

    public function view(User $user, Status $status): bool
    {
        return $user->currentTeam?->id === $status->team_id;
    }

    public function create(User $user): bool
    {
        return $user->currentTeam !== null;
    }

    public function update(User $user, Status $status): bool
    {
        return $user->currentTeam?->id === $status->team_id;
    }

    /**
     * The last column cannot go -- a board with no columns has nowhere to put
     * an epic, and every epic would silently lose its status.
     */
    public function delete(User $user, Status $status): bool
    {
        if ($user->currentTeam?->id !== $status->team_id) {
            return false;
        }

        return $status->team->statuses()->count() > 1;
    }
}
