<?php

use App\Models\Epic;
use App\Models\QuarterPlan;
use App\Models\Squad;
use App\Models\Status;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(\Database\Seeders\StatusSeeder::class);
});

it('can view planning page', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)
        ->get('/planning')
        ->assertSuccessful();
});

it('defaults to next quarter', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user);

    $component = Livewire::test('planning.show');
    $selectedQuarter = $component->get('selectedQuarter');

    // Verify it's a valid quarter format (Q1-Q4-YYYY)
    expect($selectedQuarter)->toBeString()->toMatch('/^Q[1-4]-\d{4}$/');

    // Verify it's in the available quarters list
    $availableQuarters = $component->viewData('availableQuarters');
    expect($availableQuarters)->toContain($selectedQuarter);
});

it('can set squad capacity for a quarter', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $squad = Squad::factory()->for($user->currentTeam)->create();

    $this->actingAs($user);

    Livewire::test('planning.show')
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadId', $squad->id)
        ->set('editingCapacityValue', 100);

    $this->assertDatabaseHas('quarter_plans', [
        'team_id' => $user->currentTeam->id,
        'squad_id' => $squad->id,
        'year' => 2026,
        'quarter' => 1,
        'available_story_points' => 100,
    ]);
});

it('can update existing squad capacity', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $squad = Squad::factory()->for($user->currentTeam)->create();
    $quarterPlan = QuarterPlan::factory()
        ->for($user->currentTeam)
        ->for($squad)
        ->create([
            'year' => 2026,
            'quarter' => 1,
            'available_story_points' => 50,
        ]);

    $this->actingAs($user);

    Livewire::test('planning.show')
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadId', $squad->id)
        ->set('editingCapacityValue', 75);

    $quarterPlan->refresh();
    expect($quarterPlan->available_story_points)->toBe(75);
});

it('can add epic to plan', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $squad = Squad::factory()->for($user->currentTeam)->create();
    $status = Status::first();
    $epic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create();

    $this->actingAs($user);

    Livewire::test('planning.show')
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadId', $squad->id)
        ->call('addEpicToPlan', $epic->id);

    $this->assertDatabaseHas('epic_squad', [
        'epic_id' => $epic->id,
        'squad_id' => $squad->id,
        'planned_quarter' => 'Q1-2026',
        'story_points' => null,
    ]);
});

it('can update epic story points inline', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $squad = Squad::factory()->for($user->currentTeam)->create();
    $status = Status::first();
    $epic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create();
    $epic->squads()->attach($squad, [
        'planned_quarter' => 'Q1-2026',
        'story_points' => 20,
    ]);

    $this->actingAs($user);

    Livewire::test('planning.show')
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadId', $squad->id)
        ->call('updateEpicStoryPoints', $epic->id, 30);

    $this->assertDatabaseHas('epic_squad', [
        'epic_id' => $epic->id,
        'squad_id' => $squad->id,
        'planned_quarter' => 'Q1-2026',
        'story_points' => 30,
    ]);
});

it('can remove epic from plan', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $squad = Squad::factory()->for($user->currentTeam)->create();
    $status = Status::first();
    $epic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create();
    $epic->squads()->attach($squad, [
        'planned_quarter' => 'Q1-2026',
        'story_points' => 20,
    ]);

    $this->actingAs($user);

    Livewire::test('planning.show')
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadId', $squad->id)
        ->call('removeEpicFromPlan', $epic->id);

    $this->assertDatabaseHas('epic_squad', [
        'epic_id' => $epic->id,
        'squad_id' => $squad->id,
        'planned_quarter' => null,
        'story_points' => null,
    ]);
});

it('calculates total allocated points correctly', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $squad = Squad::factory()->for($user->currentTeam)->create();
    $status = Status::first();

    $quarterPlan = QuarterPlan::factory()
        ->for($user->currentTeam)
        ->for($squad)
        ->create([
            'year' => 2026,
            'quarter' => 1,
            'available_story_points' => 100,
        ]);

    $epic1 = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create();
    $epic1->squads()->attach($squad, [
        'planned_quarter' => 'Q1-2026',
        'story_points' => 30,
    ]);

    $epic2 = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create();
    $epic2->squads()->attach($squad, [
        'planned_quarter' => 'Q1-2026',
        'story_points' => 40,
    ]);

    $this->actingAs($user);

    $component = Livewire::test('planning.show')
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadId', $squad->id);

    $capacity = $component->viewData('capacity');
    $totalAllocated = $component->viewData('totalAllocated');

    expect($capacity)->toBe(100)
        ->and($totalAllocated)->toBe(70);
});

it('shows over-allocation when allocated exceeds capacity', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $squad = Squad::factory()->for($user->currentTeam)->create();
    $status = Status::first();

    $quarterPlan = QuarterPlan::factory()
        ->for($user->currentTeam)
        ->for($squad)
        ->create([
            'year' => 2026,
            'quarter' => 1,
            'available_story_points' => 50,
        ]);

    $epic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create();
    $epic->squads()->attach($squad, [
        'planned_quarter' => 'Q1-2026',
        'story_points' => 75,
    ]);

    $this->actingAs($user);

    $component = Livewire::test('planning.show')
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadId', $squad->id);

    $capacity = $component->viewData('capacity');
    $totalAllocated = $component->viewData('totalAllocated');

    expect($capacity)->toBe(50)
        ->and($totalAllocated)->toBe(75)
        ->and($totalAllocated)->toBeGreaterThan($capacity);
});

it('only counts epics planned for selected quarter', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $squad = Squad::factory()->for($user->currentTeam)->create();
    $status = Status::first();

    $quarterPlan = QuarterPlan::factory()
        ->for($user->currentTeam)
        ->for($squad)
        ->create([
            'year' => 2026,
            'quarter' => 1,
            'available_story_points' => 100,
        ]);

    // Epic planned for Q1-2026
    $epic1 = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create();
    $epic1->squads()->attach($squad, [
        'planned_quarter' => 'Q1-2026',
        'story_points' => 30,
    ]);

    // Epic planned for Q2-2026 (should not count)
    $epic2 = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create();
    $epic2->squads()->attach($squad, [
        'planned_quarter' => 'Q2-2026',
        'story_points' => 50,
    ]);

    $this->actingAs($user);

    $component = Livewire::test('planning.show')
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadId', $squad->id);

    $totalAllocated = $component->viewData('totalAllocated');
    expect($totalAllocated)->toBe(30);
});

it('shows relevant epics for selected squad and quarter', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $squad = Squad::factory()->for($user->currentTeam)->create();
    $status = Status::first();

    // Epic that overlaps Q1-2026 but isn't planned for it
    $overlappingEpic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create([
            'start_date' => '2025-12-01',
            'end_date' => '2026-02-15',
        ]);

    // Epic planned for Q1-2026
    $plannedEpic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create();
    $plannedEpic->squads()->attach($squad, [
        'planned_quarter' => 'Q1-2026',
        'story_points' => 25,
    ]);

    $this->actingAs($user);

    $component = Livewire::test('planning.show')
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadId', $squad->id);

    $plannedEpics = $component->viewData('plannedEpics');
    $availableEpics = $component->viewData('availableEpics');

    expect($plannedEpics)->toBeInstanceOf(\Illuminate\Support\Collection::class)
        ->and($plannedEpics)->toHaveCount(1)
        ->and($plannedEpics->pluck('id')->toArray())->toContain($plannedEpic->id)
        ->and($availableEpics)->toBeInstanceOf(\Illuminate\Support\Collection::class)
        ->and($availableEpics)->toHaveCount(1)
        ->and($availableEpics->pluck('id')->toArray())->toContain($overlappingEpic->id);
});

it('cannot add epic from another team', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $otherTeam = Team::factory()->create();
    $squad = Squad::factory()->for($user->currentTeam)->create();
    $status = Status::first();
    $otherEpic = Epic::factory()
        ->for($otherTeam)
        ->for($status)
        ->create();

    $this->actingAs($user);

    Livewire::test('planning.show')
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadId', $squad->id)
        ->call('addEpicToPlan', $otherEpic->id)
        ->assertForbidden();
});
