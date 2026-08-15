<?php

use App\Models\Allocation;
use App\Models\Category;
use App\Models\Engineer;
use App\Models\Epic;
use App\Models\EpicQuarterPlan;
use App\Models\Squad;
use App\Models\Status;
use App\Models\User;
use App\Support\Quarter;
use App\Support\WeekCalendar;
use Livewire\Livewire;

/**
 * The edit page has no save button -- every field writes on change, and the
 * week spine writes allocations directly. These cover both halves.
 */
beforeEach(function () {
    $user = User::factory()->withPersonalTeam()->create();
    $this->user = $user;
    $this->team = $user->currentTeam;
    $this->team->update(['week_starts_on' => 2]);

    $this->squad = Squad::create(['team_id' => $this->team->id, 'name' => 'Charging', 'color' => '#EF4444']);

    $this->engineer = Engineer::create([
        'team_id' => $this->team->id,
        'squad_id' => $this->squad->id,
        'name' => 'Sarah Chen',
        'default_weekly_points' => 10,
    ]);

    $this->backlog = Status::create([
        'team_id' => $this->team->id, 'name' => 'Backlog', 'color' => '#71717A', 'is_default' => true,
    ]);
    $this->shipped = Status::create([
        'team_id' => $this->team->id, 'name' => 'Shipped', 'color' => '#3B82F6', 'is_complete' => true,
    ]);

    $this->epic = Epic::create([
        'team_id' => $this->team->id,
        'title' => 'Smart Charging Scheduler',
        'status_id' => $this->backlog->id,
        'priority' => 'medium',
    ]);

    $this->quarter = Quarter::current();
    $this->weeks = WeekCalendar::forTeam($this->team)->weeksIn($this->quarter);

    $this->actingAs($user);
});

it('saves a field as soon as it changes', function () {
    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->set('title', 'Smart Charging v2')
        ->set('priority', 'critical')
        ->assertDispatched('epic-saved');

    expect($this->epic->fresh())
        ->title->toBe('Smart Charging v2')
        ->priority->toBe('critical');
});

it('keeps an invalid field out of the database', function () {
    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->set('title', '')
        ->assertHasErrors(['title' => 'required']);

    expect($this->epic->fresh()->title)->toBe('Smart Charging Scheduler');
});

it('rejects an end date before the start date', function () {
    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->set('start_date', '2026-03-01')
        ->set('end_date', '2026-01-01')
        ->assertHasErrors(['end_date']);

    expect($this->epic->fresh()->end_date)->toBeNull();
});

it('lets one bad field through without blocking the next one', function () {
    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->set('end_date', 'not-a-date')
        ->set('status_id', (string) $this->shipped->id);

    expect($this->epic->fresh()->status_id)->toBe($this->shipped->id);
});

it('closes an open pause when the status changes', function () {
    $pause = App\Models\EpicPause::create([
        'epic_id' => $this->epic->id,
        'paused_at' => $this->weeks[0],
        'reason' => 'Blocked on vendor certification',
    ]);

    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->set('status_id', (string) $this->shipped->id);

    expect($pause->fresh()->resumed_at)->not->toBeNull();
});

it('leaves an open pause alone when some other field changes', function () {
    $pause = App\Models\EpicPause::create([
        'epic_id' => $this->epic->id,
        'paused_at' => $this->weeks[0],
        'reason' => 'Blocked on vendor certification',
    ]);

    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->set('priority', 'high');

    expect($pause->fresh()->resumed_at)->toBeNull();
});

it('writes a category change straight away', function () {
    $category = Category::create(['team_id' => $this->team->id, 'name' => 'Compliance', 'color' => '#0EA5E9']);

    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->set('category_id', (string) $category->id);

    expect($this->epic->fresh()->category_id)->toBe($category->id);
});

it('creates a quarter plan when a squad is picked', function () {
    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->set('squad_ids', [$this->squad->id]);

    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $this->epic->id,
        'squad_id' => $this->squad->id,
        'year' => $this->quarter->year,
        'quarter' => $this->quarter->quarter,
        'planned_points' => 25,
    ]);
});

it('drops a squad plan for this quarter only', function () {
    $next = $this->quarter->next();

    foreach ([$this->quarter, $next] as $quarter) {
        EpicQuarterPlan::create([
            'epic_id' => $this->epic->id,
            'squad_id' => $this->squad->id,
            'year' => $quarter->year,
            'quarter' => $quarter->quarter,
            'planned_points' => 30,
        ]);
    }

    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->set('quarter', $this->quarter->key())
        ->set('squad_ids', []);

    expect(EpicQuarterPlan::where('epic_id', $this->epic->id)->count())->toBe(1)
        ->and(EpicQuarterPlan::where('epic_id', $this->epic->id)->first()->year)->toBe($next->year);
});

it('saves planned and delivered points on change', function () {
    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->set('squad_ids', [$this->squad->id])
        ->set("planned_points.{$this->squad->id}", 40)
        ->set("delivered_points.{$this->squad->id}", 12);

    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $this->epic->id,
        'squad_id' => $this->squad->id,
        'planned_points' => 40,
        'delivered_points' => 12,
    ]);
});

it('books someone across the whole quarter when the epic has no dates', function () {
    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->call('addEngineer', $this->engineer->id);

    expect(Allocation::where('epic_id', $this->epic->id)->count())->toBe(count($this->weeks));
});

it('books only the weeks the epic runs when it has dates', function () {
    $this->epic->update([
        'start_date' => $this->weeks[1]->toDateString(),
        'end_date' => $this->weeks[2]->addDays(6)->toDateString(),
    ]);

    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->call('addEngineer', $this->engineer->id);

    expect(Allocation::where('epic_id', $this->epic->id)->pluck('week_start')
        ->map(fn ($w) => $w->toDateString())->sort()->values()->all())
        ->toBe([$this->weeks[1]->toDateString(), $this->weeks[2]->toDateString()]);
});

it('toggles a single week on and off from the spine', function () {
    $week = $this->weeks[0]->toDateString();

    $page = Livewire::test('epics.edit', ['epic' => $this->epic])
        ->call('toggleWeek', $this->engineer->id, $week);

    expect(Allocation::count())->toBe(1);

    $page->call('toggleWeek', $this->engineer->id, $week);

    expect(Allocation::count())->toBe(0);
});

it('paints and erases a run of weeks in one call', function () {
    $page = Livewire::test('epics.edit', ['epic' => $this->epic])
        ->call('paintWeeks', $this->engineer->id, $this->weeks[0]->toDateString(), $this->weeks[3]->toDateString());

    expect(Allocation::count())->toBe(4);

    $page->call('paintWeeks', $this->engineer->id, $this->weeks[0]->toDateString(), $this->weeks[1]->toDateString(), true);

    expect(Allocation::count())->toBe(2);
});

it('takes someone off this quarter but leaves other quarters alone', function () {
    $outside = WeekCalendar::forTeam($this->team)->weeksIn($this->quarter->next())[0];

    Allocation::create([
        'engineer_id' => $this->engineer->id,
        'epic_id' => $this->epic->id,
        'week_start' => $this->weeks[0]->toDateString(),
        'share' => 1.0,
    ]);

    Allocation::create([
        'engineer_id' => $this->engineer->id,
        'epic_id' => $this->epic->id,
        'week_start' => $outside->toDateString(),
        'share' => 1.0,
    ]);

    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->call('removeEngineer', $this->engineer->id);

    expect(Allocation::count())->toBe(1)
        ->and(Allocation::first()->week_start->toDateString())->toBe($outside->toDateString());
});

it('refuses to staff an engineer from another team', function () {
    $stranger = Engineer::create([
        'team_id' => User::factory()->withPersonalTeam()->create()->currentTeam->id,
        'name' => 'Outsider',
        'default_weekly_points' => 10,
    ]);

    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->call('addEngineer', $stranger->id)
        ->assertForbidden();

    expect(Allocation::count())->toBe(0);
});

it('opens on the quarter the epic actually runs in', function () {
    $next = $this->quarter->next();

    EpicQuarterPlan::create([
        'epic_id' => $this->epic->id,
        'squad_id' => $this->squad->id,
        'year' => $next->year,
        'quarter' => $next->quarter,
        'planned_points' => 55,
    ]);

    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->assertSet('quarter', $next->key())
        ->assertSet('squad_ids', [$this->squad->id])
        ->assertSet("planned_points.{$this->squad->id}", 55);
});

it('reloads squads and points when the quarter changes', function () {
    $next = $this->quarter->next();

    EpicQuarterPlan::create([
        'epic_id' => $this->epic->id,
        'squad_id' => $this->squad->id,
        'year' => $next->year,
        'quarter' => $next->quarter,
        'planned_points' => 55,
    ]);

    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->assertSet('squad_ids', [$this->squad->id])
        ->set('quarter', $this->quarter->key())
        ->assertSet('squad_ids', [])
        ->set('quarter', $next->key())
        ->assertSet('squad_ids', [$this->squad->id])
        ->assertSet("planned_points.{$this->squad->id}", 55);
});
