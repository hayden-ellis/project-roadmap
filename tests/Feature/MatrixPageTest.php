<?php

use App\Models\Epic;
use App\Models\EpicQuarterPlan;
use App\Models\Squad;
use App\Models\Status;
use App\Models\User;
use Livewire\Livewire;

/**
 * The matrix is a statement, not a computation: priority only seeds where an
 * epic starts, and from then on the quadrant is wherever somebody dropped it.
 */
beforeEach(function () {
    $user = User::factory()->withPersonalTeam()->create();
    $this->team = $user->currentTeam;

    $this->makeEpic = fn (string $title, string $priority = 'medium', array $attributes = []) => Epic::create([
        'team_id' => $this->team->id,
        'title' => $title,
        'priority' => $priority,
    ] + $attributes);

    $this->planInto = function (Epic $epic, Squad $squad, int $year = 2026, int $quarter = 3, ?int $points = null) {
        return EpicQuarterPlan::create([
            'epic_id' => $epic->id,
            'squad_id' => $squad->id,
            'year' => $year,
            'quarter' => $quarter,
            'planned_points' => $points,
        ]);
    };

    $this->actingAs($user);
});

/** @return \Illuminate\Support\Collection<int, Epic> */
function quadrant($component, string $key)
{
    return collect($component->viewData('byQuadrant'))[$key] ?? collect();
}

it('seeds a new epic into the quadrant its priority implies', function () {
    expect(($this->makeEpic)('Payments outage', 'critical'))
        ->importance->toBe('high')
        ->urgency->toBe('urgent');

    expect(($this->makeEpic)('Checkout redesign', 'high'))
        ->importance->toBe('high')
        ->urgency->toBe('not_urgent');

    expect(($this->makeEpic)('Dead code cleanup', 'low'))
        ->importance->toBe('low')
        ->urgency->toBe('not_urgent');
});

it('appends new epics to the end of their quadrant', function () {
    $first = ($this->makeEpic)('First', 'critical');
    $second = ($this->makeEpic)('Second', 'critical');

    expect($second->matrix_order)->toBeGreaterThan($first->matrix_order);
});

it('groups epics by quadrant', function () {
    $urgent = ($this->makeEpic)('Payments outage', 'critical');
    $someday = ($this->makeEpic)('Dead code cleanup', 'low');

    $component = Livewire::test('matrix');

    expect(quadrant($component, 'high/urgent')->pluck('id'))->toContain($urgent->id)
        ->and(quadrant($component, 'low/not_urgent')->pluck('id'))->toContain($someday->id);
});

it('moves an epic to the quadrant it was dropped into, at the dropped position', function () {
    $a = ($this->makeEpic)('A', 'critical');
    $b = ($this->makeEpic)('B', 'critical');
    $c = ($this->makeEpic)('C', 'low');

    Livewire::test('matrix')->call('moveEpic', $c->id, 0, 'high/urgent');

    $c->refresh();

    expect($c->importance)->toBe('high')
        ->and($c->urgency)->toBe('urgent')
        ->and([$c->fresh()->matrix_order, $a->fresh()->matrix_order, $b->fresh()->matrix_order])
        ->toBe([0, 1, 2]);
});

it('keeps hidden epics in place when dragging under a filter', function () {
    $squad = Squad::create(['team_id' => $this->team->id, 'name' => 'Charging', 'color' => '#EF4444']);

    $hidden = ($this->makeEpic)('Hidden', 'critical');
    $first = ($this->makeEpic)('First', 'critical');
    $second = ($this->makeEpic)('Second', 'critical');

    ($this->planInto)($first, $squad);
    ($this->planInto)($second, $squad);

    // Only the squad's two epics are visible; drop Second above First.
    Livewire::test('matrix')
        ->set('selectedSquadIds', [$squad->id])
        ->call('moveEpic', $second->id, 0, 'high/urgent');

    expect(Epic::whereKey([$hidden->id, $first->id, $second->id])->get()->sortBy('matrix_order')->pluck('title')->values()->all())
        ->toBe(['Hidden', 'Second', 'First']);
});

it('filters the matrix to the selected squads', function () {
    $charging = Squad::create(['team_id' => $this->team->id, 'name' => 'Charging', 'color' => '#EF4444']);
    $billing = Squad::create(['team_id' => $this->team->id, 'name' => 'Billing', 'color' => '#3B82F6']);

    $ours = ($this->makeEpic)('Ours', 'critical');
    $theirs = ($this->makeEpic)('Theirs', 'critical');

    ($this->planInto)($ours, $charging);
    ($this->planInto)($theirs, $billing);

    $component = Livewire::test('matrix')->set('selectedSquadIds', [$charging->id]);

    expect(quadrant($component, 'high/urgent')->pluck('id'))
        ->toContain($ours->id)
        ->not->toContain($theirs->id);
});

it('filters the matrix to the selected statuses', function () {
    $planned = Status::create(['team_id' => $this->team->id, 'name' => 'Planned', 'color' => '#3B82F6']);
    $active = Status::create(['team_id' => $this->team->id, 'name' => 'Active', 'color' => '#22C55E']);

    $ours = ($this->makeEpic)('Ours', 'critical', ['status_id' => $planned->id]);
    $theirs = ($this->makeEpic)('Theirs', 'critical', ['status_id' => $active->id]);

    $component = Livewire::test('matrix')->set('selectedStatusIds', [$planned->id]);

    expect(quadrant($component, 'high/urgent')->pluck('id'))
        ->toContain($ours->id)
        ->not->toContain($theirs->id);
});

it('offers only unfinished statuses as filter options', function () {
    $active = Status::create(['team_id' => $this->team->id, 'name' => 'Active', 'color' => '#22C55E']);
    $done = Status::create(['team_id' => $this->team->id, 'name' => 'Done', 'color' => '#A1A1AA', 'is_complete' => true]);

    $options = Livewire::test('matrix')->viewData('statuses')->pluck('id');

    expect($options)->toContain($active->id)->not->toContain($done->id);
});

it('filters the matrix to the selected quarter', function () {
    $squad = Squad::create(['team_id' => $this->team->id, 'name' => 'Charging', 'color' => '#EF4444']);

    $now = ($this->makeEpic)('This quarter', 'critical');
    $later = ($this->makeEpic)('Next year', 'critical');

    ($this->planInto)($now, $squad, 2026, 3);
    ($this->planInto)($later, $squad, 2027, 1);

    $component = Livewire::test('matrix')->set('selectedQuarter', '2026-Q3');

    expect(quadrant($component, 'high/urgent')->pluck('id'))
        ->toContain($now->id)
        ->not->toContain($later->id);
});

it('quick-adds an epic from the bar', function () {
    Livewire::test('matrix')
        ->set('newTitle', 'Payments List View')
        ->set('newPriority', 'high')
        ->call('quickAdd')
        ->assertHasNoErrors()
        ->assertSet('newTitle', '');

    $epic = $this->team->epics()->where('title', 'Payments List View')->first();

    expect($epic)->not->toBeNull()
        ->and($epic->importance)->toBe('high')
        ->and($epic->urgency)->toBe('not_urgent');
});

it('will not move an epic belonging to another team', function () {
    $stranger = User::factory()->withPersonalTeam()->create();

    $foreign = Epic::create([
        'team_id' => $stranger->currentTeam->id,
        'title' => 'Their work',
    ]);

    Livewire::test('matrix')
        ->call('moveEpic', $foreign->id, 0, 'high/urgent')
        ->assertForbidden();

    expect($foreign->fresh()->importance)->toBe('low');
});

it('rejects a quadrant that does not exist', function () {
    $epic = ($this->makeEpic)('A', 'medium');

    Livewire::test('matrix')
        ->call('moveEpic', $epic->id, 0, 'high/sideways')
        ->assertStatus(400);
});
