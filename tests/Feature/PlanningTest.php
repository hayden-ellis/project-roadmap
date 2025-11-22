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

    // Common setup for most tests
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->squad = Squad::factory()->for($this->team)->create();
    $this->status = Status::first();

    // Act as the user by default
    $this->actingAs($this->user);
});

it('can view planning page', function () {
    $this->get('/planning')->assertSuccessful();
});

it('defaults to next quarter', function () {
    $component = Livewire::test('planning.show', ['plan' => null]);
    $selectedQuarter = $component->get('selectedQuarter');

    // Verify it's a valid quarter format (Q1-Q4-YYYY)
    expect($selectedQuarter)->toBeString()->toMatch('/^Q[1-4]-\d{4}$/');

    // Verify it's in the available quarters list
    $availableQuarters = $component->viewData('availableQuarters');
    expect($availableQuarters)->toContain($selectedQuarter);
});

it('can set squad capacity for a quarter', function () {
    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadId', $this->squad->id)
        ->set('editingCapacityValue', 100);

    $this->assertDatabaseHas('quarter_plans', [
        'team_id' => $this->team->id,
        'squad_id' => $this->squad->id,
        'year' => 2026,
        'quarter' => 1,
        'available_story_points' => 100,
    ]);
});

it('can update existing squad capacity', function () {
    $quarterPlan = QuarterPlan::factory()
        ->for($this->team)
        ->for($this->squad)
        ->create([
            'year' => 2026,
            'quarter' => 1,
            'available_story_points' => 50,
        ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadId', $this->squad->id)
        ->set('editingCapacityValue', 75);

    $quarterPlan->refresh();
    expect($quarterPlan->available_story_points)->toBe(75);
});

it('can add epic to plan', function () {
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create();

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadId', $this->squad->id)
        ->call('addEpicToPlan', $epic->id);

    $this->assertDatabaseHas('epic_squad', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'planned_quarter' => 'Q1-2026',
        'story_points' => null,
    ]);
});

it('can update epic story points inline', function () {
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create();
    $epic->squads()->attach($this->squad, [
        'planned_quarter' => 'Q1-2026',
        'story_points' => 20,
    ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadId', $this->squad->id)
        ->call('updateEpicStoryPoints', $epic->id, 30);

    $this->assertDatabaseHas('epic_squad', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'planned_quarter' => 'Q1-2026',
        'story_points' => 30,
    ]);
});

it('can remove epic from plan', function () {
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create();
    $epic->squads()->attach($this->squad, [
        'planned_quarter' => 'Q1-2026',
        'story_points' => 20,
    ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadId', $this->squad->id)
        ->call('removeEpicFromPlan', $epic->id);

    // Story points should be preserved when removing from plan
    $this->assertDatabaseHas('epic_squad', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'planned_quarter' => null,
        'story_points' => 20, // Points are preserved
    ]);
});

it('calculates total allocated points correctly', function () {
    $quarterPlan = QuarterPlan::factory()
        ->for($this->team)
        ->for($this->squad)
        ->create([
            'year' => 2026,
            'quarter' => 1,
            'available_story_points' => 100,
        ]);

    $epic1 = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create();
    $epic1->squads()->attach($this->squad, [
        'planned_quarter' => 'Q1-2026',
        'story_points' => 30,
    ]);

    $epic2 = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create();
    $epic2->squads()->attach($this->squad, [
        'planned_quarter' => 'Q1-2026',
        'story_points' => 40,
    ]);

    $component = Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadId', $this->squad->id);

    $capacity = $component->viewData('capacity');
    $totalAllocated = $component->viewData('totalAllocated');

    expect($capacity)->toBe(100)
        ->and($totalAllocated)->toBe(70);
});

it('shows over-allocation when allocated exceeds capacity', function () {
    $quarterPlan = QuarterPlan::factory()
        ->for($this->team)
        ->for($this->squad)
        ->create([
            'year' => 2026,
            'quarter' => 1,
            'available_story_points' => 50,
        ]);

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create();
    $epic->squads()->attach($this->squad, [
        'planned_quarter' => 'Q1-2026',
        'story_points' => 75,
    ]);

    $component = Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadId', $this->squad->id);

    $capacity = $component->viewData('capacity');
    $totalAllocated = $component->viewData('totalAllocated');

    expect($capacity)->toBe(50)
        ->and($totalAllocated)->toBe(75)
        ->and($totalAllocated)->toBeGreaterThan($capacity);
});

it('only counts epics planned for selected quarter', function () {
    $quarterPlan = QuarterPlan::factory()
        ->for($this->team)
        ->for($this->squad)
        ->create([
            'year' => 2026,
            'quarter' => 1,
            'available_story_points' => 100,
        ]);

    // Epic planned for Q1-2026
    $epic1 = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create();
    $epic1->squads()->attach($this->squad, [
        'planned_quarter' => 'Q1-2026',
        'story_points' => 30,
    ]);

    // Epic planned for Q2-2026 (should not count)
    $epic2 = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create();
    $epic2->squads()->attach($this->squad, [
        'planned_quarter' => 'Q2-2026',
        'story_points' => 50,
    ]);

    $component = Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadId', $this->squad->id);

    $totalAllocated = $component->viewData('totalAllocated');
    expect($totalAllocated)->toBe(30);
});

it('shows relevant epics for selected squad and quarter', function () {
    // Epic that overlaps Q1-2026, is assigned to squad, but isn't planned for it
    $overlappingEpic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create([
            'start_date' => '2025-12-01',
            'end_date' => '2026-02-15',
        ]);
    $overlappingEpic->squads()->attach($this->squad); // Assigned to squad but not planned

    // Epic planned for Q1-2026
    $plannedEpic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create();
    $plannedEpic->squads()->attach($this->squad, [
        'planned_quarter' => 'Q1-2026',
        'story_points' => 25,
    ]);

    $component = Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadId', $this->squad->id);

    $plannedEpics = $component->viewData('plannedEpics');
    $availableEpics = $component->viewData('availableEpics');

    expect($plannedEpics)->toBeInstanceOf(\Illuminate\Support\Collection::class)
        ->and($plannedEpics)->toHaveCount(1)
        ->and($plannedEpics->pluck('id')->toArray())->toContain($plannedEpic->id)
        ->and($availableEpics)->toBeInstanceOf(\Illuminate\Support\Collection::class)
        ->and($availableEpics)->toHaveCount(1)
        ->and($availableEpics->pluck('id')->toArray())->toContain($overlappingEpic->id);
});

it('only shows epics assigned to the selected squad', function () {
    $squad1 = Squad::factory()->for($this->team)->create(['name' => 'Squad 1']);
    $squad2 = Squad::factory()->for($this->team)->create(['name' => 'Squad 2']);

    // Epic assigned to squad1 only (with dates overlapping Q1-2026)
    $squad1Epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create([
            'title' => 'Squad 1 Epic',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);
    $squad1Epic->squads()->attach($squad1);

    // Epic assigned to squad2 only (with dates overlapping Q1-2026)
    $squad2Epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create([
            'title' => 'Squad 2 Epic',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);
    $squad2Epic->squads()->attach($squad2);

    // Epic not assigned to any squad (with dates overlapping Q1-2026)
    $unassignedEpic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create([
            'title' => 'Unassigned Epic',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);

    $component = Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadId', $squad1->id);

    $availableEpics = $component->viewData('availableEpics');

    // Should only see squad1's epic
    expect($availableEpics)->toHaveCount(1)
        ->and($availableEpics->pluck('id')->toArray())->toContain($squad1Epic->id)
        ->and($availableEpics->pluck('id')->toArray())->not->toContain($squad2Epic->id)
        ->and($availableEpics->pluck('id')->toArray())->not->toContain($unassignedEpic->id);
});

it('cannot add epic from another team', function () {
    $otherTeam = Team::factory()->create();
    $otherEpic = Epic::factory()
        ->for($otherTeam)
        ->for($this->status)
        ->create();

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadId', $this->squad->id)
        ->call('addEpicToPlan', $otherEpic->id)
        ->assertForbidden();
});
