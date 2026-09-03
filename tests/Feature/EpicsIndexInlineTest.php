<?php

use App\Models\Allocation;
use App\Models\Engineer;
use App\Models\Epic;
use App\Models\EpicPause;
use App\Models\Status;
use App\Models\Team;
use App\Models\User;
use App\Services\CapacityService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/**
 * The epics list edits title and status in the row itself. These cover the
 * two writes, their guards, and the crew column.
 */
beforeEach(function () {
    $user = User::factory()->withPersonalTeam()->create();
    $this->user = $user;
    $this->team = $user->currentTeam;

    $this->backlog = Status::create([
        'team_id' => $this->team->id, 'name' => 'Backlog', 'color' => '#71717A', 'is_default' => true,
    ]);
    $this->building = Status::create([
        'team_id' => $this->team->id, 'name' => 'Building', 'color' => '#22C55E',
    ]);

    $this->epic = Epic::create([
        'team_id' => $this->team->id,
        'title' => 'Smart Charging Scheduler',
        'status_id' => $this->backlog->id,
        'priority' => 'medium',
    ]);

    $this->actingAs($user);
});

it('renames an epic from its row', function () {
    Livewire::test('epics.index')
        ->call('saveTitle', $this->epic->id, '  Smarter Charging  ');

    expect($this->epic->fresh()->title)->toBe('Smarter Charging');
});

it('ignores an empty title', function () {
    Livewire::test('epics.index')
        ->call('saveTitle', $this->epic->id, '   ');

    expect($this->epic->fresh()->title)->toBe('Smart Charging Scheduler');
});

it('moves an epic to another status and closes its open pause', function () {
    EpicPause::create([
        'epic_id' => $this->epic->id,
        'reason' => 'Waiting on design',
        'paused_at' => now()->subWeek(),
    ]);

    Livewire::test('epics.index')
        ->call('setStatus', $this->epic->id, $this->building->id);

    expect($this->epic->fresh()->status_id)->toBe($this->building->id)
        ->and($this->epic->pauses()->open()->count())->toBe(0);
});

it('clears the status', function () {
    Livewire::test('epics.index')
        ->call('setStatus', $this->epic->id, null);

    expect($this->epic->fresh()->status_id)->toBeNull();
});

it('refuses a status that belongs to another team', function () {
    $otherTeam = Team::factory()->create();
    $foreign = Status::create(['team_id' => $otherTeam->id, 'name' => 'Elsewhere', 'color' => '#000000']);

    Livewire::test('epics.index')
        ->call('setStatus', $this->epic->id, $foreign->id);

    expect($this->epic->fresh()->status_id)->toBe($this->backlog->id);
});

it('cannot touch an epic from another team', function () {
    $otherTeam = Team::factory()->create();
    $foreign = Epic::create(['team_id' => $otherTeam->id, 'title' => 'Not yours', 'priority' => 'low']);

    expect(fn () => Livewire::test('epics.index')->call('saveTitle', $foreign->id, 'Mine now'))
        ->toThrow(ModelNotFoundException::class)
        ->and($foreign->fresh()->title)->toBe('Not yours');
});

it('shows who is booked on the epic this week', function () {
    $engineer = Engineer::create([
        'team_id' => $this->team->id,
        'name' => 'Sarah Chen',
        'default_weekly_points' => 10,
    ]);

    Allocation::create([
        'engineer_id' => $engineer->id,
        'epic_id' => $this->epic->id,
        'week_start' => CapacityService::for($this->team)->currentWeek()->toDateString(),
    ]);

    Livewire::test('epics.index')
        ->assertSee('Sarah Chen');
});
