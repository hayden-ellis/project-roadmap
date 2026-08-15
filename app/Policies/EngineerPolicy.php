<?php

namespace App\Policies;

use App\Models\Engineer;
use App\Models\User;

class EngineerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->currentTeam !== null;
    }

    public function view(User $user, Engineer $engineer): bool
    {
        return $user->currentTeam?->id === $engineer->team_id;
    }

    public function create(User $user): bool
    {
        return $user->currentTeam !== null;
    }

    public function update(User $user, Engineer $engineer): bool
    {
        return $user->currentTeam?->id === $engineer->team_id;
    }

    public function delete(User $user, Engineer $engineer): bool
    {
        return $user->currentTeam?->id === $engineer->team_id;
    }
}
