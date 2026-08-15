<?php

namespace App\Support;

use App\Models\Team;
use Carbon\CarbonImmutable;

/**
 * Resolves planning weeks against a team's configured start day.
 *
 * Sprints currently start on Tuesday, but nothing here assumes that -- the
 * anchor comes from teams.week_starts_on. Every week_start date stored in
 * allocations and engineer_week_capacity is snapped through this class, so
 * the whole grid re-anchors by changing one column.
 */
final readonly class WeekCalendar
{
    /** @param int $startsOn ISO day of week, 1 = Monday ... 7 = Sunday. */
    public function __construct(public int $startsOn = 2)
    {
        if ($startsOn < 1 || $startsOn > 7) {
            throw new \InvalidArgumentException("week_starts_on must be 1-7, got {$startsOn}.");
        }
    }

    public static function forTeam(Team $team): self
    {
        return new self($team->week_starts_on ?? 2);
    }

    /** Snaps any date back to the most recent week start (itself if it matches). */
    public function snap(\DateTimeInterface $date): CarbonImmutable
    {
        $date = CarbonImmutable::instance($date)->startOfDay();
        $drift = ($date->dayOfWeekIso - $this->startsOn + 7) % 7;

        return $date->subDays($drift);
    }

    public function current(): CarbonImmutable
    {
        return $this->snap(CarbonImmutable::now());
    }

    public function next(\DateTimeInterface $weekStart): CarbonImmutable
    {
        return $this->snap($weekStart)->addWeek();
    }

    /**
     * Every week start belonging to a quarter.
     *
     * A week belongs to the quarter containing its *start date*, so boundary
     * weeks land in exactly one quarter and none are double counted. Quarters
     * therefore hold 12-14 weeks depending on where the anchor day falls,
     * which is why capacity spreads divide by the real count rather than 13.
     *
     * @return array<int, CarbonImmutable>
     */
    public function weeksIn(Quarter $quarter): array
    {
        $cursor = $this->snap($quarter->start());

        // The snap may land in the previous quarter; that week belongs there.
        if ($cursor->lt($quarter->start())) {
            $cursor = $cursor->addWeek();
        }

        $end = $quarter->end();
        $weeks = [];

        while ($cursor->lte($end)) {
            $weeks[] = $cursor;
            $cursor = $cursor->addWeek();
        }

        return $weeks;
    }

    public function countIn(Quarter $quarter): int
    {
        return count($this->weeksIn($quarter));
    }

    /**
     * Splits a total evenly across a quarter's weeks, distributing the
     * remainder across the earliest weeks so the parts sum to exactly $total.
     *
     * @return array<string, int> keyed by Y-m-d week start
     */
    public function spread(int $total, Quarter $quarter): array
    {
        $weeks = $this->weeksIn($quarter);
        $count = count($weeks);

        if ($count === 0) {
            return [];
        }

        $base = intdiv($total, $count);
        $remainder = $total - ($base * $count);

        $spread = [];
        foreach ($weeks as $i => $week) {
            $spread[$week->toDateString()] = $base + ($i < $remainder ? 1 : 0);
        }

        return $spread;
    }
}
