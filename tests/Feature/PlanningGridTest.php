<?php

use App\Models\Allocation;
use App\Models\Engineer;
use App\Models\Epic;
use App\Models\Squad;
use App\Models\Status;
use App\Models\User;
use App\Support\Quarter;
use App\Support\WeekCalendar;
use Livewire\Livewire;

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

    $this->status = Status::create([
        'team_id' => $this->team->id,
        'name' => 'In progress',
        'color' => '#10B981',
        'is_default' => true,
    ]);

    $this->epic = Epic::create([
        'team_id' => $this->team->id,
        'title' => 'Smart Charging Scheduler',
        'status_id' => $this->status->id,
    ]);

    $this->weeks = WeekCalendar::forTeam($this->team)->weeksIn(Quarter::current());

    $this->actingAs($user);
});

it('assigns an engineer to an epic for a week', function () {
    Livewire::test('planning.grid')
        ->set('brushEpicId', $this->epic->id)
        ->call('toggleCell', $this->engineer->id, $this->weeks[0]->toDateString());

    $this->assertDatabaseHas('allocations', [
        'engineer_id' => $this->engineer->id,
        'epic_id' => $this->epic->id,
        'share' => 1.0,
    ]);
});

it('unassigns when the same cell is clicked again', function () {
    $week = $this->weeks[0]->toDateString();

    $component = Livewire::test('planning.grid')
        ->set('brushEpicId', $this->epic->id)
        ->call('toggleCell', $this->engineer->id, $week);

    expect(Allocation::count())->toBe(1);

    $component->call('toggleCell', $this->engineer->id, $week);

    expect(Allocation::count())->toBe(0);
});

it('does nothing without an epic selected', function () {
    Livewire::test('planning.grid')
        ->call('toggleCell', $this->engineer->id, $this->weeks[0]->toDateString());

    expect(Allocation::count())->toBe(0);
});

it('paints a contiguous range in one call', function () {
    Livewire::test('planning.grid')
        ->set('brushEpicId', $this->epic->id)
        ->call('paintRange', $this->engineer->id, $this->weeks[2]->toDateString(), $this->weeks[6]->toDateString());

    expect(Allocation::count())->toBe(5)
        ->and(Allocation::min('week_start'))->toContain($this->weeks[2]->toDateString())
        ->and(Allocation::max('week_start'))->toContain($this->weeks[6]->toDateString());
});

it('paints a range dragged backwards', function () {
    Livewire::test('planning.grid')
        ->set('brushEpicId', $this->epic->id)
        ->call('paintRange', $this->engineer->id, $this->weeks[6]->toDateString(), $this->weeks[2]->toDateString());

    expect(Allocation::count())->toBe(5);
});

it('erases a range without touching other epics', function () {
    $other = Epic::create([
        'team_id' => $this->team->id,
        'title' => 'Other work',
    ]);

    foreach (array_slice($this->weeks, 0, 4) as $week) {
        Allocation::create(['engineer_id' => $this->engineer->id, 'epic_id' => $this->epic->id, 'week_start' => $week]);
        Allocation::create(['engineer_id' => $this->engineer->id, 'epic_id' => $other->id, 'week_start' => $week]);
    }

    Livewire::test('planning.grid')
        ->set('brushEpicId', $this->epic->id)
        ->call('paintRange', $this->engineer->id, $this->weeks[0]->toDateString(), $this->weeks[3]->toDateString(), true);

    expect(Allocation::where('epic_id', $this->epic->id)->count())->toBe(0)
        ->and(Allocation::where('epic_id', $other->id)->count())->toBe(4);
});

it('does not duplicate when painting over existing assignments', function () {
    Livewire::test('planning.grid')
        ->set('brushEpicId', $this->epic->id)
        ->call('paintRange', $this->engineer->id, $this->weeks[0]->toDateString(), $this->weeks[3]->toDateString())
        ->call('paintRange', $this->engineer->id, $this->weeks[0]->toDateString(), $this->weeks[3]->toDateString());

    expect(Allocation::count())->toBe(4);
});

it('allows a collision so the clash is visible', function () {
    $other = Epic::create([
        'team_id' => $this->team->id,
        'title' => 'Competing work',
    ]);

    $week = $this->weeks[0]->toDateString();

    Livewire::test('planning.grid')
        ->set('brushEpicId', $this->epic->id)
        ->call('toggleCell', $this->engineer->id, $week)
        ->set('brushEpicId', $other->id)
        ->call('toggleCell', $this->engineer->id, $week);

    expect(Allocation::where('week_start', $week)->count())->toBe(2)
        ->and($this->team->capacity()->isOverAllocated($this->engineer, $this->weeks[0]))->toBeTrue();
});

it('refuses to assign an engineer from another team', function () {
    $otherUser = User::factory()->withPersonalTeam()->create();
    $foreign = Engineer::create([
        'team_id' => $otherUser->currentTeam->id,
        'name' => 'Not Mine',
        'default_weekly_points' => 10,
    ]);

    Livewire::test('planning.grid')
        ->set('brushEpicId', $this->epic->id)
        ->call('toggleCell', $foreign->id, $this->weeks[0]->toDateString())
        ->assertForbidden();

    expect(Allocation::count())->toBe(0);
});

it('refuses to assign to an epic from another team', function () {
    $otherUser = User::factory()->withPersonalTeam()->create();
    $foreignEpic = Epic::create([
        'team_id' => $otherUser->currentTeam->id,
        'title' => 'Their epic',
    ]);

    Livewire::test('planning.grid')
        ->set('brushEpicId', $foreignEpic->id)
        ->call('toggleCell', $this->engineer->id, $this->weeks[0]->toDateString())
        ->assertForbidden();

    expect(Allocation::count())->toBe(0);
});
