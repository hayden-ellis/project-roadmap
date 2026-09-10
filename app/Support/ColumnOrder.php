<?php

namespace App\Support;

use App\Models\Team;
use App\Models\User;
use App\Models\UserColumnOrder;
use Illuminate\Support\Collection;

/**
 * The order one user sees the board's columns in.
 *
 * The team order set on /statuses is the default. A user who drags a column
 * gets their own arrangement, kept per team, laid over that default: statuses
 * in the saved list come first in saved order, and any the list has never
 * seen -- created since -- follow in team order. A deleted status simply
 * drops out. Resetting deletes the row, and the team order shows through.
 */
final class ColumnOrder
{
    /**
     * @param  Collection<int, \App\Models\Status>  $statuses  in team order
     * @return Collection<int, \App\Models\Status>
     */
    public static function apply(User $user, Team $team, Collection $statuses): Collection
    {
        $rank = array_flip(self::saved($user, $team));

        if ($rank === []) {
            return $statuses->values();
        }

        [$known, $unknown] = $statuses->partition(fn ($status) => isset($rank[$status->id]));

        return $known
            ->sortBy(fn ($status) => $rank[$status->id])
            ->concat($unknown)
            ->values();
    }

    public static function isCustom(User $user, Team $team): bool
    {
        return self::saved($user, $team) !== [];
    }

    /** @param  array<int, int>  $statusIds */
    public static function save(User $user, Team $team, array $statusIds): void
    {
        UserColumnOrder::updateOrCreate(
            ['user_id' => $user->id, 'team_id' => $team->id],
            ['status_ids' => array_values(array_map('intval', $statusIds))],
        );
    }

    public static function reset(User $user, Team $team): void
    {
        UserColumnOrder::query()
            ->where('user_id', $user->id)
            ->where('team_id', $team->id)
            ->delete();
    }

    /** @return array<int, int> */
    private static function saved(User $user, Team $team): array
    {
        $order = UserColumnOrder::query()
            ->where('user_id', $user->id)
            ->where('team_id', $team->id)
            ->first();

        return array_map('intval', $order?->status_ids ?? []);
    }
}
