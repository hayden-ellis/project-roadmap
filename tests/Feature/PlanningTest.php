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
        ->set('selectedSquadIds', [$this->squad->id])
        ->set('editingCapacityValues.'.$this->squad->id, 100)
        ->call('saveCapacityForSquad', $this->squad->id);

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
        ->set('selectedSquadIds', [$this->squad->id])
        ->set('editingCapacityValues.'.$this->squad->id, 75)
        ->call('saveCapacityForSquad', $this->squad->id);

    $quarterPlan->refresh();
    expect($quarterPlan->available_story_points)->toBe(75);
});

it('can add epic to plan', function () {
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create();
    // Attach epic to squad first (required for planning)
    $epic->squads()->attach($this->squad->id);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('addEpicToPlan', $epic->id, [$this->squad->id]);

    $this->assertDatabaseHas('epic_squad', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'planned_quarter' => 'Q1-2026',
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
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('updateEpicStoryPoints', $epic->id, $this->squad->id, 30);

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
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('removeEpicFromPlan', $epic->id, $this->squad->id);

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
        ->set('selectedSquadIds', [$this->squad->id]);

    $squadData = $component->viewData('squadData');

    expect($squadData[$this->squad->id]['capacity'])->toBe(100)
        ->and($squadData[$this->squad->id]['allocated'])->toBe(70);
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
        ->set('selectedSquadIds', [$this->squad->id]);

    $squadData = $component->viewData('squadData');

    expect($squadData[$this->squad->id]['capacity'])->toBe(50)
        ->and($squadData[$this->squad->id]['allocated'])->toBe(75)
        ->and($squadData[$this->squad->id]['is_over_allocated'])->toBeTrue();
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
        ->set('selectedSquadIds', [$this->squad->id]);

    $squadData = $component->viewData('squadData');
    expect($squadData[$this->squad->id]['allocated'])->toBe(30);
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
        ->set('selectedSquadIds', [$this->squad->id]);

    $squadData = $component->viewData('squadData');
    $plannedEpics = $squadData[$this->squad->id]['planned_epics'];
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
        ->set('selectedSquadIds', [$squad1->id]);

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
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('addEpicToPlan', $otherEpic->id, [$this->squad->id])
        ->assertForbidden();
});

// Multi-Squad Tests

it('can select multiple squads', function () {
    $squad1 = Squad::factory()->for($this->team)->create(['name' => 'Squad 1']);
    $squad2 = Squad::factory()->for($this->team)->create(['name' => 'Squad 2']);

    $component = Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$squad1->id, $squad2->id]);

    expect($component->get('selectedSquadIds'))->toHaveCount(2)
        ->and($component->viewData('isMultiSquadView'))->toBeTrue();
});

it('shows shared epics when planned for multiple selected squads', function () {
    $squad1 = Squad::factory()->for($this->team)->create(['name' => 'Charging']);
    $squad2 = Squad::factory()->for($this->team)->create(['name' => 'Pricing']);

    // Epic planned for both squads
    $sharedEpic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create(['title' => 'Shared Epic']);
    $sharedEpic->squads()->attach($squad1, ['planned_quarter' => 'Q1-2026', 'story_points' => 10]);
    $sharedEpic->squads()->attach($squad2, ['planned_quarter' => 'Q1-2026', 'story_points' => 15]);

    // Epic planned for only squad1
    $uniqueEpic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create(['title' => 'Unique Epic']);
    $uniqueEpic->squads()->attach($squad1, ['planned_quarter' => 'Q1-2026', 'story_points' => 20]);

    $component = Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$squad1->id, $squad2->id]);

    $sharedEpics = $component->viewData('sharedEpics');
    $squadData = $component->viewData('squadData');

    // Shared epic should be in sharedEpics
    expect($sharedEpics)->toHaveCount(1)
        ->and($sharedEpics->first()->id)->toBe($sharedEpic->id);

    // Unique epic should only be in squad1's planned_epics (unique to that squad)
    expect($squadData[$squad1->id]['planned_epics'])->toHaveCount(1)
        ->and($squadData[$squad1->id]['planned_epics']->first()->id)->toBe($uniqueEpic->id);

    // Squad2 should have no unique epics
    expect($squadData[$squad2->id]['planned_epics'])->toHaveCount(0);
});

it('can add epic to multiple squads at once', function () {
    $squad1 = Squad::factory()->for($this->team)->create(['name' => 'Squad 1']);
    $squad2 = Squad::factory()->for($this->team)->create(['name' => 'Squad 2']);

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create();
    // Attach epic to both squads (but not planned yet)
    $epic->squads()->attach([$squad1->id, $squad2->id]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$squad1->id, $squad2->id])
        ->call('addEpicToPlan', $epic->id, [$squad1->id, $squad2->id]);

    $this->assertDatabaseHas('epic_squad', [
        'epic_id' => $epic->id,
        'squad_id' => $squad1->id,
        'planned_quarter' => 'Q1-2026',
    ]);
    $this->assertDatabaseHas('epic_squad', [
        'epic_id' => $epic->id,
        'squad_id' => $squad2->id,
        'planned_quarter' => 'Q1-2026',
    ]);
});

it('maintains independent story points per squad', function () {
    $squad1 = Squad::factory()->for($this->team)->create(['name' => 'Squad 1']);
    $squad2 = Squad::factory()->for($this->team)->create(['name' => 'Squad 2']);

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create();
    $epic->squads()->attach($squad1, ['planned_quarter' => 'Q1-2026', 'story_points' => 10]);
    $epic->squads()->attach($squad2, ['planned_quarter' => 'Q1-2026', 'story_points' => 20]);

    // Update only squad1's points
    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$squad1->id, $squad2->id])
        ->call('updateEpicStoryPoints', $epic->id, $squad1->id, 15);

    // Squad 1's points updated
    $this->assertDatabaseHas('epic_squad', [
        'epic_id' => $epic->id,
        'squad_id' => $squad1->id,
        'story_points' => 15,
    ]);
    // Squad 2's points unchanged
    $this->assertDatabaseHas('epic_squad', [
        'epic_id' => $epic->id,
        'squad_id' => $squad2->id,
        'story_points' => 20,
    ]);
});

it('tracks capacity independently per squad in multi-squad view', function () {
    $squad1 = Squad::factory()->for($this->team)->create(['name' => 'Squad 1']);
    $squad2 = Squad::factory()->for($this->team)->create(['name' => 'Squad 2']);

    QuarterPlan::factory()->for($this->team)->for($squad1)->create([
        'year' => 2026,
        'quarter' => 1,
        'available_story_points' => 100,
    ]);
    QuarterPlan::factory()->for($this->team)->for($squad2)->create([
        'year' => 2026,
        'quarter' => 1,
        'available_story_points' => 80,
    ]);

    // Add epics with different points for each squad
    $epic1 = Epic::factory()->for($this->team)->for($this->status)->create();
    $epic1->squads()->attach($squad1, ['planned_quarter' => 'Q1-2026', 'story_points' => 30]);

    $epic2 = Epic::factory()->for($this->team)->for($this->status)->create();
    $epic2->squads()->attach($squad2, ['planned_quarter' => 'Q1-2026', 'story_points' => 50]);

    $component = Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$squad1->id, $squad2->id]);

    $squadData = $component->viewData('squadData');

    expect($squadData[$squad1->id]['capacity'])->toBe(100)
        ->and($squadData[$squad1->id]['allocated'])->toBe(30)
        ->and($squadData[$squad1->id]['remaining'])->toBe(70)
        ->and($squadData[$squad2->id]['capacity'])->toBe(80)
        ->and($squadData[$squad2->id]['allocated'])->toBe(50)
        ->and($squadData[$squad2->id]['remaining'])->toBe(30);
});

it('shows available epics that can be added to any selected squad', function () {
    $squad1 = Squad::factory()->for($this->team)->create(['name' => 'Squad 1']);
    $squad2 = Squad::factory()->for($this->team)->create(['name' => 'Squad 2']);

    // Epic assigned to both squads but only planned for squad1
    // Dates must overlap with Q1-2026 for it to appear in available epics
    $partiallyPlannedEpic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create([
            'title' => 'Partially Planned',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);
    $partiallyPlannedEpic->squads()->attach($squad1, ['planned_quarter' => 'Q1-2026', 'story_points' => 10]);
    $partiallyPlannedEpic->squads()->attach($squad2); // Not planned for squad2

    $component = Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$squad1->id, $squad2->id]);

    $availableEpics = $component->viewData('availableEpics');

    // Epic should show as available (because it can still be added to squad2)
    expect($availableEpics)->toHaveCount(1)
        ->and($availableEpics->first()->id)->toBe($partiallyPlannedEpic->id)
        ->and($availableEpics->first()->available_for_squad_ids)->toContain($squad2->id)
        ->and($availableEpics->first()->available_for_squad_ids)->not->toContain($squad1->id);
});
