<?php

namespace App\Support;

use App\Models\EpicComment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use WeakMap;

/**
 * @mentions in comment bodies.
 *
 * A mention is "@" followed by a team member's full name, exactly as the
 * composer's picker inserts it. The body is kept as plain text: names are
 * resolved against the team when the comment is saved, and the result lives
 * on the comment's mentions relation. Nobody outside the team ever resolves,
 * and a name that is not a member is just text.
 */
final class Mentions
{
    /**
     * The members named in a body.
     *
     * One pass with every name as an alternative, longest first: each
     * stretch of text is claimed once, so "@Priya Sharma-Lee" is her and
     * not also "@Priya Sharma".
     *
     * @return Collection<int, User>
     */
    public static function resolve(Team $team, string $body): Collection
    {
        $members = self::members($team);

        if ($members->isEmpty()) {
            return collect();
        }

        preg_match_all(self::pattern($members->pluck('name')), $body, $found);

        $named = collect($found[1])->map(fn ($name) => mb_strtolower($name))->unique();

        return $members
            ->filter(fn (User $user) => $named->contains(mb_strtolower($user->name)))
            ->values();
    }

    /**
     * The comment body, escaped, with every member named wrapped for styling.
     *
     * Styled from the team, not the mentions relation: that relation is who
     * was told, and it leaves out the author naming themselves. On the page
     * the name should still read as a name.
     */
    public static function render(EpicComment $comment, ?Team $team = null): HtmlString
    {
        $html = e($comment->body);
        $members = self::members($team ?? $comment->epic->team);

        if ($members->isNotEmpty()) {
            $html = preg_replace(
                self::pattern($members->map(fn (User $user) => e($user->name))),
                '<span class="'.self::CHIP.'">$0</span>',
                $html,
            );
        }

        return new HtmlString($html);
    }

    /** How a mention reads in a comment: a quiet tinted chip. */
    public const CHIP = 'inline rounded-md px-1 py-px font-medium bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300';

    /**
     * Members with logins, as the picker wants them.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    public static function choices(Team $team): Collection
    {
        return self::members($team)
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'avatar' => $user->profile_photo_url])
            ->values();
    }

    /** @var WeakMap<Team, Collection<int, User>> */
    private static ?WeakMap $members = null;

    /**
     * @return Collection<int, User> longest name first
     *
     * Remembered per Team instance: a thread renders one comment at a
     * time, and each one asking the database for the same roster would
     * add a query per comment. Held weakly, so it lives no longer than
     * the model it belongs to.
     */
    private static function members(Team $team): Collection
    {
        self::$members ??= new WeakMap;

        return self::$members[$team] ??= $team->allUsers()
            ->unique('id')
            ->sortByDesc(fn (User $user) => mb_strlen($user->name))
            ->values();
    }

    /**
     * "@Name" as a whole token, for any of the names given (longest first):
     * nothing word-like right before the @ or right after the name.
     *
     * @param  Collection<int, string>  $names
     */
    private static function pattern(Collection $names): string
    {
        $alternatives = $names->map(fn ($name) => preg_quote($name, '/'))->implode('|');

        return '/(?<![\p{L}\p{N}])@('.$alternatives.')(?![\p{L}\p{N}])/iu';
    }
}
