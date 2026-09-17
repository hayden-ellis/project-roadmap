<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Epic;
use App\Models\EpicActivity;
use App\Models\Status;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Writes an epic's history.
 *
 * The Epic model's created and updated hooks land here, so every path that
 * saves an epic -- the board flyout, the epic page, an inline rename, the MCP
 * server -- is remembered without knowing it. The two places that move many
 * epics with one query, which Eloquent does not announce, call recordBulk
 * themselves.
 */
final class EpicHistory
{
    /** The first row: how the epic looked when it arrived. */
    public static function recordCreated(Epic $epic): void
    {
        $changes = [];

        foreach (EpicActivity::TRACKED as $field) {
            $value = self::scalar($field, $epic->getAttribute($field));

            if ($value === null || $value === false) {
                continue;
            }

            $changes[$field] = self::entry($field, null, $value);
        }

        self::write($epic, 'created', $changes);
    }

    /** Only the tracked columns, and only when one of them actually moved. */
    public static function recordUpdated(Epic $epic): void
    {
        $changes = [];

        foreach (EpicActivity::TRACKED as $field) {
            if (! $epic->wasChanged($field)) {
                continue;
            }

            $from = self::scalar($field, $epic->getOriginal($field));
            $to = self::scalar($field, $epic->getAttribute($field));

            // '' and null are the same absence; Eloquent counts the swap as
            // a change, history does not.
            if ($from === $to) {
                continue;
            }

            $changes[$field] = self::entry($field, $from, $to);
        }

        if ($changes === []) {
            return;
        }

        self::write($epic, 'updated', $changes);
    }

    /**
     * For a mass update that bypasses model events: the caller says what
     * moved and to where, and every epic gets the same row.
     *
     * @param  Collection<int, Epic>  $epics
     */
    public static function recordBulk(
        Collection $epics,
        string $field,
        mixed $from,
        ?string $fromLabel,
        mixed $to,
        ?string $toLabel,
    ): void {
        if ($epics->isEmpty()) {
            return;
        }

        $actor = Auth::user();
        $now = now();

        $change = ['from' => self::scalar($field, $from), 'to' => self::scalar($field, $to)];

        if (in_array($field, ['status_id', 'category_id'], true)) {
            $change['from_label'] = $fromLabel;
            $change['to_label'] = $toLabel;
        }

        EpicActivity::insert($epics->map(fn (Epic $epic) => [
            'epic_id' => $epic->id,
            'user_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'event' => 'updated',
            'source' => self::source($actor),
            'diff' => json_encode([$field => $change]),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    /**
     * @return array{from: mixed, to: mixed, from_label?: ?string, to_label?: ?string}
     */
    private static function entry(string $field, mixed $from, mixed $to): array
    {
        $entry = ['from' => $from, 'to' => $to];

        // The name as it is right now: the old row still exists at this
        // point, so both sides can be read off the table.
        if ($field === 'status_id') {
            $entry['from_label'] = $from ? Status::find($from)?->name : null;
            $entry['to_label'] = $to ? Status::find($to)?->name : null;
        } elseif ($field === 'category_id') {
            $entry['from_label'] = $from ? Category::find($from)?->name : null;
            $entry['to_label'] = $to ? Category::find($to)?->name : null;
        }

        return $entry;
    }

    /** Dates as Y-m-d, ids as ints, blanks as null, everything else as it is. */
    private static function scalar(string $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return match ($field) {
            'status_id', 'category_id', 'release_percent' => (int) $value,
            'is_recurring' => (bool) $value,
            'start_date', 'end_date' => substr((string) $value, 0, 10),
            default => $value,
        };
    }

    /**
     * @param  array<string, array{from: mixed, to: mixed, from_label?: ?string, to_label?: ?string}>  $changes
     */
    private static function write(Epic $epic, string $event, array $changes): void
    {
        $actor = Auth::user();

        $epic->activities()->create([
            'user_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'event' => $event,
            'source' => self::source($actor),
            'diff' => $changes,
        ]);
    }

    /**
     * A real bearer token means the MCP server; a browser session carries a
     * transient token, or none at all.
     */
    private static function source(?User $actor): string
    {
        if (! $actor) {
            return 'system';
        }

        return $actor->currentAccessToken() instanceof PersonalAccessToken ? 'mcp' : 'web';
    }
}
