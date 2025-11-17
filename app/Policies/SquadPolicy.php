<?php

namespace App\Policies;

use App\Models\Squad;
use App\Models\User;

class SquadPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->currentTeam !== null;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Squad $squad): bool
    {
        return $user->currentTeam->id === $squad->team_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->currentTeam !== null;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Squad $squad): bool
    {
        return $user->currentTeam->id === $squad->team_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Squad $squad): bool
    {
        return $user->currentTeam->id === $squad->team_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Squad $squad): bool
    {
        return $user->currentTeam->id === $squad->team_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Squad $squad): bool
    {
        return $user->currentTeam->id === $squad->team_id;
    }
}
