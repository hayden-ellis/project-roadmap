<?php

namespace Database\Seeders;

use App\Models\Allocation;
use App\Models\Category;
use App\Models\Engineer;
use App\Models\EngineerQuarterCapacity;
use App\Models\EngineerWeekCapacity;
use App\Models\Epic;
use App\Models\EpicPause;
use App\Models\EpicQuarterPlan;
use App\Models\Squad;
use App\Models\Status;
use App\Models\User;
use App\Support\Quarter;
use App\Support\WeekCalendar;
use Illuminate\Database\Seeder;

/**
 * Hand-built demo data rather than random fixtures, so every state the UI can
 * show is actually present: active work, paused work with a reason and a
 * culprit, work that has not started, something shipped, an over-allocated
 * engineer, and PTO that shortens a quarter.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::factory()->withPersonalTeam()->create([
            'name' => 'Hayden Ellis',
            'email' => 'hre0001@outlook.com',
            'password' => 'password',
        ]);

        $team = $user->currentTeam;
        $team->update(['name' => 'Platform', 'week_starts_on' => 2]);

        $calendar = WeekCalendar::forTeam($team);
        $quarter = Quarter::current();
        $weeks = $calendar->weeksIn($quarter);

        // ---------------------------------------------------------- taxonomy
        $categories = collect([
            'Product' => '#3B82F6',
            'Platform' => '#8B5CF6',
            'Reliability' => '#F59E0B',
            'Compliance' => '#EC4899',
        ])->map(fn ($color, $name) => Category::create([
            'team_id' => $team->id,
            'name' => $name,
            'color' => $color,
            'sort_order' => 0,
            'is_default' => $name === 'Product',
        ]))->keyBy('name');

        // The board's columns. Order here is column order on the Now page.
        $statuses = collect([
            ['name' => 'Backlog', 'color' => '#71717A', 'description' => 'Not committed to yet'],
            ['name' => 'Up next', 'color' => '#6366F1', 'description' => 'Committed, not started'],
            ['name' => 'In progress', 'color' => '#10B981', 'description' => 'Being worked on now'],
            ['name' => 'Paused', 'color' => '#F59E0B', 'description' => 'Stopped, with a reason', 'requires_reason' => true],
            ['name' => 'Shipped', 'color' => '#3B82F6', 'description' => 'Done and out', 'is_complete' => true],
        ])->map(fn ($row) => Status::create([
            'team_id' => $team->id,
            'name' => $row['name'],
            'color' => $row['color'],
            'description' => $row['description'],
            'is_default' => $row['name'] === 'Backlog',
            'is_complete' => $row['is_complete'] ?? false,
            'requires_reason' => $row['requires_reason'] ?? false,
        ]))->keyBy('name');

        $squads = collect([
            ['name' => 'Charging', 'color' => '#EF4444', 'description' => 'EV charging infrastructure'],
            ['name' => 'Payments', 'color' => '#10B981', 'description' => 'Payment processing and integration'],
            ['name' => 'Analytics', 'color' => '#3B82F6', 'description' => 'Data analytics and reporting'],
        ])->map(fn ($attrs, $i) => Squad::create($attrs + ['team_id' => $team->id, 'sort_order' => $i]))
            ->keyBy('name');

        // ------------------------------------------------------------ roster
        $roster = [
            ['Sarah Chen', 'Charging', 'Staff Engineer', 150],
            ['Raj Patel', 'Charging', 'Senior Engineer', 130],
            ['Mia Okafor', 'Charging', 'Senior Engineer', 140],
            ['Tom Lindqvist', 'Charging', 'Engineer', 60],
            ['Elena Rossi', 'Payments', 'Staff Engineer', 150],
            ['Dan Whitfield', 'Payments', 'Senior Engineer', 140],
            ['Priya Nair', 'Payments', 'Engineer', 130],
            ['Ben Achebe', 'Analytics', 'Staff Engineer', 150],
            ['Yuki Tanaka', 'Analytics', 'Senior Engineer', 110],
        ];

        $engineers = collect($roster)->map(function ($row, $i) use ($team, $squads, $quarter) {
            [$name, $squad, $title, $points] = $row;

            $engineer = Engineer::create([
                'team_id' => $team->id,
                'squad_id' => $squads[$squad]->id,
                'name' => $name,
                'email' => str($name)->lower()->replace(' ', '.').'@example.com',
                'title' => $title,
                'default_weekly_points' => 10,
                'is_active' => true,
                'sort_order' => $i,
            ]);

            EngineerQuarterCapacity::create([
                'engineer_id' => $engineer->id,
                'year' => $quarter->year,
                'quarter' => $quarter->quarter,
                'available_points' => $points,
            ]);

            return $engineer;
        })->keyBy('name');

        // Week-level reality: PTO, and a new joiner who ramps in mid-quarter.
        foreach ([8, 9] as $i) {
            EngineerWeekCapacity::create([
                'engineer_id' => $engineers['Mia Okafor']->id,
                'week_start' => $weeks[$i],
                'available_points' => 0,
                'note' => 'Annual leave',
            ]);
        }

        foreach (range(0, 5) as $i) {
            EngineerWeekCapacity::create([
                'engineer_id' => $engineers['Tom Lindqvist']->id,
                'week_start' => $weeks[$i],
                'available_points' => 0,
                'note' => 'Starts mid-quarter',
            ]);
        }

        // ------------------------------------------------------------- epics
        $definitions = [
            // title, squad, category, status, planned, delivered, recurring
            ['Smart Charging Scheduler', 'Charging', 'Product', 'In progress', 320, 130, false],
            ['Charger Fault Telemetry', 'Charging', 'Reliability', 'In progress', 90, 25, false],
            ['CCS Protocol Upgrade', 'Charging', 'Platform', 'Backlog', 60, null, false],
            ['Checkout Redesign', 'Payments', 'Product', 'In progress', 260, 110, false],
            ['Payment Retry Logic', 'Payments', 'Reliability', 'In progress', 150, 55, false],
            ['PSD2 Compliance', 'Payments', 'Compliance', 'Paused', 110, 15, false],
            ['Wallet Top-ups', 'Payments', 'Product', 'Backlog', 70, null, false],
            ['Usage Reporting V2', 'Analytics', 'Product', 'In progress', 220, 90, false],
            ['Realtime Ingest Pipeline', 'Analytics', 'Platform', 'In progress', 120, 20, false],
            ['Data Warehouse Migration', 'Analytics', 'Platform', 'Shipped', 100, 100, false],
            ['Observability Baseline', 'Analytics', 'Reliability', 'Up next', 40, null, true],
        ];

        $epics = collect($definitions)->map(function ($row, $i) use ($team, $squads, $categories, $statuses, $quarter) {
            [$title, $squad, $category, $status, $planned, $delivered, $recurring] = $row;

            $epic = Epic::create([
                'team_id' => $team->id,
                'category_id' => $categories[$category]->id,
                'title' => $title,
                'description' => "{$title} — seeded demo epic.",
                'status_id' => $statuses[$status]->id,
                'priority' => ['high', 'medium', 'critical'][$i % 3],
                'start_date' => $quarter->start(),
                'end_date' => $quarter->end(),
                'is_recurring' => $recurring,
            ]);

            EpicQuarterPlan::create([
                'epic_id' => $epic->id,
                'squad_id' => $squads[$squad]->id,
                'year' => $quarter->year,
                'quarter' => $quarter->quarter,
                'planned_points' => $planned,
                'delivered_points' => $delivered,
            ]);

            return $epic;
        })->keyBy('title');

        // ------------------------------------------------------- allocations
        // Ranges are [firstWeekIndex, lastWeekIndex] inclusive. Epics that stop
        // before the current week are what make the Now page show "paused".
        $assignments = [
            ['Sarah Chen', 'Smart Charging Scheduler', 0, 12],
            ['Raj Patel', 'Charger Fault Telemetry', 0, 3],
            ['Raj Patel', 'Smart Charging Scheduler', 4, 12],
            ['Mia Okafor', 'Charger Fault Telemetry', 0, 3],
            ['Mia Okafor', 'Smart Charging Scheduler', 4, 12],
            ['Tom Lindqvist', 'Smart Charging Scheduler', 6, 12],

            ['Elena Rossi', 'Checkout Redesign', 0, 12],
            ['Dan Whitfield', 'PSD2 Compliance', 0, 1],
            ['Dan Whitfield', 'Checkout Redesign', 2, 12],
            ['Priya Nair', 'Payment Retry Logic', 0, 12],

            ['Ben Achebe', 'Usage Reporting V2', 0, 12],
            ['Yuki Tanaka', 'Realtime Ingest Pipeline', 0, 2],
            ['Yuki Tanaka', 'Usage Reporting V2', 3, 12],
        ];

        foreach ($assignments as [$engineer, $epic, $from, $to]) {
            foreach (range($from, min($to, count($weeks) - 1)) as $i) {
                Allocation::create([
                    'engineer_id' => $engineers[$engineer]->id,
                    'epic_id' => $epics[$epic]->id,
                    'week_start' => $weeks[$i],
                    'share' => 1.0,
                ]);
            }
        }

        // A deliberate collision so the over-allocation warning has something to
        // show: Dan is wanted on retries while already committed to Checkout.
        $currentIndex = collect($weeks)->search(
            fn ($w) => $w->toDateString() === $calendar->current()->toDateString()
        );

        if ($currentIndex !== false) {
            foreach (range($currentIndex, min($currentIndex + 2, count($weeks) - 1)) as $i) {
                Allocation::firstOrCreate([
                    'engineer_id' => $engineers['Dan Whitfield']->id,
                    'epic_id' => $epics['Payment Retry Logic']->id,
                    'week_start' => $weeks[$i],
                ], ['share' => 1.0]);
            }
        }

        // ------------------------------------------------------------ pauses
        // The half derivation cannot know: why they stopped, and what took the
        // capacity.
        EpicPause::create([
            'epic_id' => $epics['Charger Fault Telemetry']->id,
            'paused_at' => $weeks[4],
            'reason' => 'Deprioritised to land the scheduler this quarter',
            'superseded_by_epic_id' => $epics['Smart Charging Scheduler']->id,
        ]);

        EpicPause::create([
            'epic_id' => $epics['PSD2 Compliance']->id,
            'paused_at' => $weeks[2],
            'reason' => 'Blocked on vendor certification',
        ]);

        EpicPause::create([
            'epic_id' => $epics['Realtime Ingest Pipeline']->id,
            'paused_at' => $weeks[3],
            'reason' => 'Traded for reporting work the exec review needed',
            'superseded_by_epic_id' => $epics['Usage Reporting V2']->id,
        ]);
    }
}
