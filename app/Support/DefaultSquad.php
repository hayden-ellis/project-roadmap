<?php

namespace App\Support;

use App\Models\Squad;
use App\Models\Team;
use App\Models\User;
use App\Models\UserDefaultSquad;

/**
 * The squad a user's pages start filtered by.
 *
 * Resolution order: an explicit choice (including an explicit "none"), then
 * the squad of the engineer record linked to this login, then nothing. Kept
 * per team because one person can sit on several.
 *
 * The default is only ever a starting value. Each page's own filter --
 * whether it lives in the URL or the session -- still wins once touched.
 */
final class DefaultSquad
{
    public static function for(User $user, Team $team): ?Squad
    {
        $choice = UserDefaultSquad::query()
            ->where('user_id', $user->id)
            ->where('team_id', $team->id)
            ->first();

        if ($choice) {
            return $choice->squad_id ? $team->squads()->find($choice->squad_id) : null;
        }

        return $team->engineers()->where('user_id', $user->id)->first()?->squad;
    }

    public static function id(User $user, Team $team): ?int
    {
        return self::for($user, $team)?->id;
    }

    /** Applies the default to a page's multi-select when the URL left it empty. */
    public static function seed(array $current, string $queryKey, User $user, Team $team): array
    {
        if ($current !== [] || request()->query->has($queryKey)) {
            return $current;
        }

        $id = self::id($user, $team);

        return $id ? [(string) $id] : [];
    }

    /** Null means "no default", and stops inference from the engineer link. */
    public static function set(User $user, Team $team, ?Squad $squad): void
    {
        UserDefaultSquad::updateOrCreate(
            ['user_id' => $user->id, 'team_id' => $team->id],
            ['squad_id' => $squad?->id],
        );
    }
}
