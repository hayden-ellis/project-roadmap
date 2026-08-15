<?php

use App\Models\Allocation;
use App\Models\Engineer;
use App\Models\EngineerQuarterCapacity;
use App\Models\EngineerWeekCapacity;
use App\Models\Epic;
use App\Models\Squad;
use App\Models\User;
use App\Services\CapacityService;
use App\Support\Quarter;
use App\Support\WeekCalendar;

beforeEach(function () {
    $user = User::factory()->withPersonalTeam()->create();
    $this->team = $user->currentTeam;
    $this->team->update(['week_starts_on' => 2]); // Tuesday
    $this->squad = Squad::create(['team_id' => $this->team->id, 'name' => 'Charging', 'color' => '#EF4444']);
    $this->calendar = WeekCalendar::forTeam($this->team);
    $this->capacity = CapacityService::for($this->team);
    $this->quarter = Quarter::current();
    $this->weeks = $this->calendar->weeksIn($this->quarter);

    $this->engineer = fn (array $attrs = []) => Engineer::create(array_merge([
        'team_id' => $this->team->id,
        'squad_id' => $this->squad->id,
        'name' => fake()->name(),
        'default_weekly_points' => 8,
    ], $attrs));

    $this->epic = fn () => Epic::create([
        'team_id' => $this->team->id,
        'title' => fake()->unique()->sentence(3),
    ]);
});

describe('week calendar', function () {
    it('anchors weeks to the team configured start day', function () {
        expect(collect($this->weeks)->every(fn ($w) => $w->dayOfWeekIso === 2))->toBeTrue();
    });

    it('re-anchors when the team changes its start day', function () {
        $this->team->update(['week_starts_on' => 4]); // Thursday
        $weeks = WeekCalendar::forTeam($this->team->fresh())->weeksIn($this->quarter);

        expect(collect($weeks)->every(fn ($w) => $w->dayOfWeekIso === 4))->toBeTrue();
    });

    it('assigns a boundary week to exactly one quarter', function () {
        $q = $this->quarter;
        $thisQuarter = collect($this->calendar->weeksIn($q))->map->toDateString();
        $nextQuarter = collect($this->calendar->weeksIn($q->next()))->map->toDateString();

        expect($thisQuarter->intersect($nextQuarter))->toBeEmpty();
    });

    it('keeps every week start inside its own quarter', function () {
        foreach ($this->weeks as $week) {
            expect($this->quarter->contains($week))->toBeTrue();
        }
    });
});

describe('capacity spread', function () {
    it('divides a quarter total evenly across its real week count', function () {
        $count = count($this->weeks);
        $spread = $this->calendar->spread($count * 10, $this->quarter);

        expect(array_unique(array_values($spread)))->toBe([10]);
    });

    it('distributes the remainder so the parts sum to the total', function () {
        $spread = $this->calendar->spread(137, $this->quarter);

        expect(array_sum($spread))->toBe(137);
    });
});

describe('supply', function () {
    it('spreads the quarter envelope across weeks', function () {
        $engineer = ($this->engineer)();
        $total = count($this->weeks) * 10;

        EngineerQuarterCapacity::create([
            'engineer_id' => $engineer->id,
            'year' => $this->quarter->year,
            'quarter' => $this->quarter->quarter,
            'available_points' => $total,
        ]);

        expect($this->capacity->weeklyCapacity($engineer, $this->weeks[0]))->toBe(10)
            ->and($this->capacity->quarterCapacity($engineer, $this->quarter))->toBe($total);
    });

    it('falls back to the weekly default when no envelope is set', function () {
        $engineer = ($this->engineer)();

        expect($this->capacity->weeklyCapacity($engineer, $this->weeks[0]))->toBe(8)
            ->and($this->capacity->quarterCapacity($engineer, $this->quarter))->toBe(8 * count($this->weeks));
    });

    it('lets a week override beat the spread and reduce real quarter capacity', function () {
        $engineer = ($this->engineer)();
        $total = count($this->weeks) * 10;

        EngineerQuarterCapacity::create([
            'engineer_id' => $engineer->id,
            'year' => $this->quarter->year,
            'quarter' => $this->quarter->quarter,
            'available_points' => $total,
        ]);

        EngineerWeekCapacity::create([
            'engineer_id' => $engineer->id,
            'week_start' => $this->weeks[2],
            'available_points' => 0,
            'note' => 'PTO',
        ]);

        $this->capacity->flush();

        expect($this->capacity->weeklyCapacity($engineer, $this->weeks[2]))->toBe(0)
            ->and($this->capacity->quarterCapacity($engineer, $this->quarter))->toBe($total - 10)
            // The envelope as typed stays untouched -- both numbers remain available.
            ->and($this->capacity->plannedQuarterCapacity($engineer, $this->quarter))->toBe($total);
    });

    it('derives squad capacity from its active engineers', function () {
        ($this->engineer)(['default_weekly_points' => 10]);
        ($this->engineer)(['default_weekly_points' => 5]);
        ($this->engineer)(['default_weekly_points' => 99, 'is_active' => false]);

        $this->capacity->flush();

        expect($this->capacity->squadQuarterCapacity($this->squad, $this->quarter))
            ->toBe(15 * count($this->weeks));
    });
});

describe('demand', function () {
    it('derives points from capacity rather than storing them', function () {
        $engineer = ($this->engineer)(['default_weekly_points' => 10]);
        $epic = ($this->epic)();

        Allocation::create([
            'engineer_id' => $engineer->id,
            'epic_id' => $epic->id,
            'week_start' => $this->weeks[0],
        ]);

        expect($this->capacity->allocatedPoints($engineer, $this->weeks[0]))->toBe(10);

        // Changing capacity must move every derived total with it.
        $engineer->update(['default_weekly_points' => 20]);
        $this->capacity->flush();

        expect($this->capacity->allocatedPoints($engineer, $this->weeks[0]))->toBe(20);
    });

    it('reads two epics in one week as over-allocated rather than blocking it', function () {
        $engineer = ($this->engineer)();

        foreach ([($this->epic)(), ($this->epic)()] as $epic) {
            Allocation::create([
                'engineer_id' => $engineer->id,
                'epic_id' => $epic->id,
                'week_start' => $this->weeks[0],
            ]);
        }

        $this->capacity->flush();

        expect($this->capacity->allocatedShare($engineer, $this->weeks[0]))->toBe(2.0)
            ->and($this->capacity->isOverAllocated($engineer, $this->weeks[0]))->toBeTrue();
    });

    it('reports negative remaining when over-committed', function () {
        $engineer = ($this->engineer)(['default_weekly_points' => 10]);
        $epicA = ($this->epic)();
        $epicB = ($this->epic)();

        foreach ($this->weeks as $week) {
            Allocation::create(['engineer_id' => $engineer->id, 'epic_id' => $epicA->id, 'week_start' => $week]);
            Allocation::create(['engineer_id' => $engineer->id, 'epic_id' => $epicB->id, 'week_start' => $week]);
        }

        $this->capacity->flush();
        $capacity = 10 * count($this->weeks);

        expect($this->capacity->remainingPoints($engineer, $this->quarter))->toBe(-$capacity);
    });

    it('contributes zero points for a week with no capacity', function () {
        $engineer = ($this->engineer)(['default_weekly_points' => 0]);
        $epic = ($this->epic)();

        Allocation::create([
            'engineer_id' => $engineer->id,
            'epic_id' => $epic->id,
            'week_start' => $this->weeks[0],
        ]);

        $this->capacity->flush();

        expect($this->capacity->allocatedPointsOnEpic($engineer, $epic, $this->weeks[0]))->toBe(0);
    });
});

describe('staffing signals', function () {
    beforeEach(function () {
        $this->currentWeek = $this->capacity->currentWeek();
        $this->staff = function (Epic $epic) {
            Allocation::create([
                'engineer_id' => ($this->engineer)()->id,
                'epic_id' => $epic->id,
                'week_start' => $this->currentWeek,
            ]);
            $this->capacity->flush();
        };
    });

    it('knows whether anyone is on an epic this week', function () {
        $epic = ($this->epic)();

        expect($this->capacity->isStaffedInWeek($epic))->toBeFalse();

        ($this->staff)($epic);

        expect($this->capacity->isStaffedInWeek($epic))->toBeTrue();
    });

    it('stops being staffed the moment the last person comes off', function () {
        $epic = ($this->epic)();
        ($this->staff)($epic);

        Allocation::where('epic_id', $epic->id)->delete();
        $this->capacity->flush();

        expect($this->capacity->isStaffedInWeek($epic))->toBeFalse();
    });

    it('counts consecutive quiet weeks back from now', function () {
        $epic = ($this->epic)();
        $engineer = ($this->engineer)();
        $current = $this->capacity->currentWeek();

        Allocation::create([
            'engineer_id' => $engineer->id,
            'epic_id' => $epic->id,
            'week_start' => $current->subWeeks(3),
        ]);

        $this->capacity->flush();

        expect($this->capacity->weeksQuiet($epic))->toBe(3);
    });

    it('resolves a whole list of epics in one query', function () {
        $staffedEpic = ($this->epic)();
        $quietEpic = ($this->epic)();
        ($this->staff)($staffedEpic);

        $staffed = $this->capacity->staffedEpicIds();

        expect($staffed->contains($staffedEpic->id))->toBeTrue()
            ->and($staffed->contains($quietEpic->id))->toBeFalse();
    });
});

describe('derived spans', function () {
    it('derives an epic span from who is booked on it', function () {
        $engineer = ($this->engineer)(['default_weekly_points' => 10]);
        $epic = ($this->epic)();

        foreach (array_slice($this->weeks, 1, 3) as $week) {
            Allocation::create(['engineer_id' => $engineer->id, 'epic_id' => $epic->id, 'week_start' => $week]);
        }

        $this->capacity->flush();
        $span = $this->capacity->epicSpan($epic);

        expect($span['start']->toDateString())->toBe($this->weeks[1]->toDateString())
            ->and($span['end']->toDateString())->toBe($this->weeks[3]->addDays(6)->toDateString())
            ->and($span['weeks'])->toBe(3)
            ->and($span['points'])->toBe(30);
    });

    it('has no span when nobody is booked', function () {
        expect($this->capacity->epicSpan(($this->epic)()))->toBeNull();
    });
});

it('scopes every calculation to the current team', function () {
    $otherUser = User::factory()->withPersonalTeam()->create();
    $otherTeam = $otherUser->currentTeam;

    $otherEngineer = Engineer::create([
        'team_id' => $otherTeam->id,
        'name' => 'Someone Else',
        'default_weekly_points' => 50,
    ]);

    $otherEpic = Epic::create([
        'team_id' => $otherTeam->id,
        'title' => 'Other team work',
    ]);

    Allocation::create([
        'engineer_id' => $otherEngineer->id,
        'epic_id' => $otherEpic->id,
        'week_start' => $this->capacity->currentWeek(),
    ]);

    expect($this->capacity->staffedEpicIds())->not->toContain($otherEpic->id);
});
