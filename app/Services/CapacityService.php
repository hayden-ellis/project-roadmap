<?php

namespace App\Services;

use App\Models\Allocation;
use App\Models\Engineer;
use App\Models\EngineerQuarterCapacity;
use App\Models\EngineerWeekCapacity;
use App\Models\Epic;
use App\Models\Squad;
use App\Models\Team;
use App\Support\Quarter;
use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Every capacity calculation in the application.
 *
 * Two rules hold everything together:
 *
 *  1. Points are always derived, never stored. A cell is worth the engineer's
 *     capacity for that week times the allocation's share, so changing
 *     someone's capacity updates every total instantly.
 *
 *  2. Staffing answers "is anyone actually on this", which the board uses to
 *     flag epics whose status has drifted away from reality.
 *
 * Reads are memoised per quarter so the grid renders in a handful of queries
 * rather than one per cell. Call flush() after writing allocations or capacity.
 */
class CapacityService
{
    /** @var array<string, array<int, array<string, int>>> quarterKey => engineerId => Y-m-d => points */
    private array $weekCapacity = [];

    /** @var array<string, array<int, array<string, array<int, float>>>> quarterKey => engineerId => Y-m-d => epicId => share */
    private array $shares = [];

    private readonly WeekCalendar $calendar;

    public function __construct(public readonly Team $team)
    {
        $this->calendar = WeekCalendar::forTeam($team);
    }

    public static function for(Team $team): self
    {
        return new self($team);
    }

    public function calendar(): WeekCalendar
    {
        return $this->calendar;
    }

    public function currentWeek(): CarbonImmutable
    {
        return $this->calendar->current();
    }

    /** Drop memoised reads. Call after writing allocations or capacity. */
    public function flush(): void
    {
        $this->weekCapacity = [];
        $this->shares = [];
    }

    // ---------------------------------------------------------------- supply

    /**
     * Points an engineer has in a given week.
     *
     * Resolution order: an explicit week override (PTO, ramp-up), else an even
     * spread of their quarter envelope, else their standing weekly default.
     */
    public function weeklyCapacity(Engineer|int $engineer, \DateTimeInterface $week): int
    {
        $id = $engineer instanceof Engineer ? $engineer->id : $engineer;
        $week = $this->calendar->snap($week);
        $quarter = Quarter::fromDate($week);

        $this->primeCapacity($quarter);

        return $this->weekCapacity[$quarter->key()][$id][$week->toDateString()] ?? 0;
    }

    /**
     * Real capacity across a quarter, with week overrides applied.
     *
     * This is what the UI should show. It intentionally differs from the raw
     * engineer_quarter_capacity figure: booking a week of PTO genuinely
     * reduces what someone has available that quarter.
     */
    public function quarterCapacity(Engineer|int $engineer, Quarter $quarter): int
    {
        return collect($this->calendar->weeksIn($quarter))
            ->sum(fn (CarbonImmutable $week) => $this->weeklyCapacity($engineer, $week));
    }

    /** The envelope as typed in, before week-level overrides. */
    public function plannedQuarterCapacity(Engineer|int $engineer, Quarter $quarter): ?int
    {
        $id = $engineer instanceof Engineer ? $engineer->id : $engineer;

        return EngineerQuarterCapacity::where('engineer_id', $id)
            ->forQuarter($quarter)
            ->value('available_points');
    }

    /**
     * A squad's capacity is the sum of its active engineers' -- there is no
     * separately editable squad number to drift out of sync.
     */
    public function squadQuarterCapacity(Squad|int $squad, Quarter $quarter): int
    {
        $id = $squad instanceof Squad ? $squad->id : $squad;

        return $this->engineersIn($id)
            ->sum(fn (Engineer $e) => $this->quarterCapacity($e, $quarter));
    }

    // ---------------------------------------------------------------- demand

    /**
     * Total share an engineer carries in a week. 1.0 is fully booked; above
     * 1.0 means two epics want them at once, which the grid surfaces as a
     * warning rather than preventing.
     */
    public function allocatedShare(Engineer|int $engineer, \DateTimeInterface $week): float
    {
        $id = $engineer instanceof Engineer ? $engineer->id : $engineer;
        $week = $this->calendar->snap($week);
        $quarter = Quarter::fromDate($week);

        $this->primeAllocations($quarter);

        return array_sum($this->shares[$quarter->key()][$id][$week->toDateString()] ?? []);
    }

    public function isOverAllocated(Engineer|int $engineer, \DateTimeInterface $week): bool
    {
        return $this->allocatedShare($engineer, $week) > 1.0;
    }

    /** Points an engineer is committed to in a week, across all epics. */
    public function allocatedPoints(Engineer|int $engineer, \DateTimeInterface $week): int
    {
        return (int) round(
            $this->weeklyCapacity($engineer, $week) * $this->allocatedShare($engineer, $week)
        );
    }

    /** Points an engineer is committed to on one specific epic in a week. */
    public function allocatedPointsOnEpic(Engineer|int $engineer, Epic|int $epic, \DateTimeInterface $week): int
    {
        $engineerId = $engineer instanceof Engineer ? $engineer->id : $engineer;
        $epicId = $epic instanceof Epic ? $epic->id : $epic;
        $week = $this->calendar->snap($week);
        $quarter = Quarter::fromDate($week);

        $this->primeAllocations($quarter);

        $share = $this->shares[$quarter->key()][$engineerId][$week->toDateString()][$epicId] ?? 0.0;

        return (int) round($this->weeklyCapacity($engineerId, $week) * $share);
    }

    public function quarterAllocatedPoints(Engineer|int $engineer, Quarter $quarter): int
    {
        return collect($this->calendar->weeksIn($quarter))
            ->sum(fn (CarbonImmutable $week) => $this->allocatedPoints($engineer, $week));
    }

    /** Capacity left after commitments. Negative means over-committed. */
    public function remainingPoints(Engineer|int $engineer, Quarter $quarter): int
    {
        return $this->quarterCapacity($engineer, $quarter)
             - $this->quarterAllocatedPoints($engineer, $quarter);
    }

    /** What an epic is actually costing in a quarter, summed across engineers. */
    public function epicQuarterPoints(Epic|int $epic, Quarter $quarter): int
    {
        $epicId = $epic instanceof Epic ? $epic->id : $epic;
        $this->primeAllocations($quarter);

        $total = 0;
        foreach ($this->shares[$quarter->key()] ?? [] as $engineerId => $weeks) {
            foreach ($weeks as $date => $byEpic) {
                if (isset($byEpic[$epicId])) {
                    $total += (int) round(
                        $this->weeklyCapacity($engineerId, CarbonImmutable::parse($date)) * $byEpic[$epicId]
                    );
                }
            }
        }

        return $total;
    }

    public function squadAllocatedPoints(Squad|int $squad, Quarter $quarter): int
    {
        $id = $squad instanceof Squad ? $squad->id : $squad;

        return $this->engineersIn($id)
            ->sum(fn (Engineer $e) => $this->quarterAllocatedPoints($e, $quarter));
    }

    // ------------------------------------------------------------- staffing

    public function isStaffedInWeek(Epic|int $epic, ?\DateTimeInterface $week = null): bool
    {
        $epicId = $epic instanceof Epic ? $epic->id : $epic;

        return Allocation::where('epic_id', $epicId)
            ->inWeek($this->calendar->snap($week ?? CarbonImmutable::now()))
            ->exists();
    }

    /**
     * Epic ids with anyone allocated in a week -- one query, for classifying a
     * whole list without N+1.
     *
     * @return Collection<int, int>
     */
    public function staffedEpicIds(?\DateTimeInterface $week = null): Collection
    {
        return Allocation::query()
            ->inWeek($this->calendar->snap($week ?? CarbonImmutable::now()))
            ->whereHas('epic', fn ($q) => $q->where('team_id', $this->team->id))
            ->distinct()
            ->pluck('epic_id');
    }

    /**
     * How many consecutive weeks an epic has been unstaffed, counting back
     * from the current week. Drives "quiet for 3 weeks" on the Now page.
     */
    public function weeksQuiet(Epic|int $epic, int $lookback = 26): int
    {
        $epicId = $epic instanceof Epic ? $epic->id : $epic;
        $cursor = $this->currentWeek();

        $staffedWeeks = Allocation::where('epic_id', $epicId)
            ->betweenWeeks($cursor->subWeeks($lookback), $cursor)
            ->distinct()
            ->pluck('week_start')
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())
            ->flip();

        $quiet = 0;
        while ($quiet < $lookback && ! $staffedWeeks->has($cursor->toDateString())) {
            $quiet++;
            $cursor = $cursor->subWeek();
        }

        return $quiet;
    }

    // ----------------------------------------------------------------- spans

    /**
     * When an epic actually runs, derived from who is booked on it.
     *
     * Replaces the hand-typed per-squad date range that used to live on the
     * epic_squad pivot: a roadmap bar now reflects real staffing rather than
     * an intention nobody revisited.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, weeks: int, points: int}|null
     */
    public function epicSpan(Epic|int $epic, Squad|int|null $squad = null): ?array
    {
        $spans = $this->epicSpans(collect([$epic instanceof Epic ? $epic->id : $epic]), $squad);

        return $spans[$epic instanceof Epic ? $epic->id : $epic] ?? null;
    }

    /**
     * Spans for many epics in one query -- the roadmap renders dozens of bars
     * and must not issue a query per bar.
     *
     * @param  Collection<int, int>  $epicIds
     * @return array<int, array{start: CarbonImmutable, end: CarbonImmutable, weeks: int, points: int}>
     */
    public function epicSpans(Collection $epicIds, Squad|int|null $squad = null): array
    {
        if ($epicIds->isEmpty()) {
            return [];
        }

        $query = Allocation::query()
            ->whereIn('epic_id', $epicIds)
            ->whereHas('engineer', function ($q) use ($squad) {
                $q->where('team_id', $this->team->id);

                if ($squad !== null) {
                    $q->where('squad_id', $squad instanceof Squad ? $squad->id : $squad);
                }
            })
            ->get(['epic_id', 'engineer_id', 'week_start']);

        $spans = [];

        foreach ($query->groupBy('epic_id') as $epicId => $rows) {
            $weeks = $rows->pluck('week_start')->map(fn ($w) => CarbonImmutable::parse($w));

            $points = $rows->sum(fn ($row) => $this->weeklyCapacity($row->engineer_id, $row->week_start));

            $spans[$epicId] = [
                'start' => $weeks->min(),
                // A booked week covers the whole week, so the bar runs to its end.
                'end' => $weeks->max()->addDays(6),
                'weeks' => $weeks->unique()->count(),
                'points' => (int) $points,
            ];
        }

        return $spans;
    }

    /**
     * Which squads are actually working an epic, derived from allocations
     * rather than from a membership table.
     *
     * @return Collection<int, Squad>
     */
    public function squadsWorking(Epic|int $epic): Collection
    {
        $epicId = $epic instanceof Epic ? $epic->id : $epic;

        $squadIds = Allocation::where('epic_id', $epicId)
            ->join('engineers', 'engineers.id', '=', 'allocations.engineer_id')
            ->where('engineers.team_id', $this->team->id)
            ->whereNotNull('engineers.squad_id')
            ->distinct()
            ->pluck('engineers.squad_id');

        return Squad::whereIn('id', $squadIds)->ordered()->get();
    }

    // --------------------------------------------------------------- priming

    /** @return Collection<int, Engineer> */
    private function engineersIn(int $squadId): Collection
    {
        return Engineer::where('squad_id', $squadId)->active()->get();
    }

    /**
     * Resolves every engineer's weekly capacity for a quarter in three queries:
     * roster, quarter envelopes, week overrides.
     */
    private function primeCapacity(Quarter $quarter): void
    {
        $key = $quarter->key();

        if (isset($this->weekCapacity[$key])) {
            return;
        }

        $weeks = $this->calendar->weeksIn($quarter);
        $engineers = Engineer::where('team_id', $this->team->id)->get();

        $envelopes = EngineerQuarterCapacity::whereIn('engineer_id', $engineers->pluck('id'))
            ->forQuarter($quarter)
            ->pluck('available_points', 'engineer_id');

        $overrides = EngineerWeekCapacity::whereIn('engineer_id', $engineers->pluck('id'))
            ->whereBetween('week_start', [$weeks[0] ?? $quarter->start(), end($weeks) ?: $quarter->end()])
            ->get()
            ->groupBy('engineer_id');

        $resolved = [];

        foreach ($engineers as $engineer) {
            $spread = isset($envelopes[$engineer->id])
                ? $this->calendar->spread((int) $envelopes[$engineer->id], $quarter)
                : null;

            $engineerOverrides = $overrides->get($engineer->id, collect())
                ->keyBy(fn ($row) => $row->week_start->toDateString());

            foreach ($weeks as $week) {
                $date = $week->toDateString();

                $resolved[$engineer->id][$date] = match (true) {
                    $engineerOverrides->has($date) => (int) $engineerOverrides[$date]->available_points,
                    $spread !== null => $spread[$date] ?? 0,
                    default => (int) $engineer->default_weekly_points,
                };
            }
        }

        $this->weekCapacity[$key] = $resolved;
    }

    /** Loads a quarter's allocations in one query. */
    private function primeAllocations(Quarter $quarter): void
    {
        $key = $quarter->key();

        if (isset($this->shares[$key])) {
            return;
        }

        $weeks = $this->calendar->weeksIn($quarter);

        if ($weeks === []) {
            $this->shares[$key] = [];

            return;
        }

        $rows = Allocation::query()
            ->betweenWeeks($weeks[0], end($weeks))
            ->whereHas('engineer', fn ($q) => $q->where('team_id', $this->team->id))
            ->get(['engineer_id', 'epic_id', 'week_start', 'share']);

        $shares = [];
        foreach ($rows as $row) {
            $shares[$row->engineer_id][$row->week_start->toDateString()][$row->epic_id] = (float) $row->share;
        }

        $this->shares[$key] = $shares;
    }
}
