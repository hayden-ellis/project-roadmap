<?php

use App\Models\Epic;
use App\Models\EpicQuarterPlan;
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

it('defaults to current quarter', function () {
    $component = Livewire::test('planning.show', ['plan' => null]);
    $selectedQuarter = $component->get('selectedQuarter');

    // Verify it's a valid quarter format (Q1-Q4-YYYY)
    expect($selectedQuarter)->toBeString()->toMatch('/^Q[1-4]-\d{4}$/');

    // Verify it defaults to current quarter
    $currentQuarter = (int) ceil(now()->month / 3);
    $currentYear = now()->year;
    expect($selectedQuarter)->toBe("Q{$currentQuarter}-{$currentYear}");

    // Verify it's in the available quarters list
    $availableQuarters = $component->viewData('availableQuarters');
    expect($availableQuarters)->toContain($selectedQuarter);

    // Verify current quarter is the first available option
    expect($availableQuarters[0])->toBe($selectedQuarter);
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

    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
    ]);
});

it('can update epic story points inline', function () {
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create();
    $epic->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => 20,
    ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('updateEpicStoryPoints', $epic->id, $this->squad->id, 30);

    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => 30,
    ]);
});

it('can remove epic from plan', function () {
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create();
    $epic->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => 20,
    ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('removeEpicFromPlan', $epic->id, $this->squad->id);

    // Quarter plan record should be deleted (but squad assignment remains)
    $this->assertDatabaseMissing('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
    ]);
    // Squad assignment should still exist
    $this->assertDatabaseHas('epic_squad', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
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
    $epic1->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic1->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => 30,
    ]);

    $epic2 = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create();
    $epic2->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic2->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
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
    $epic->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
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
    $epic1->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic1->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => 30,
    ]);

    // Epic planned for Q2-2026 (should not count)
    $epic2 = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create();
    $epic2->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic2->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q2-2026',
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
    $plannedEpic->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $plannedEpic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
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
    $sharedEpic->squads()->attach([$squad1->id, $squad2->id]);
    EpicQuarterPlan::create(['epic_id' => $sharedEpic->id, 'squad_id' => $squad1->id, 'quarter' => 'Q1-2026', 'story_points' => 10]);
    EpicQuarterPlan::create(['epic_id' => $sharedEpic->id, 'squad_id' => $squad2->id, 'quarter' => 'Q1-2026', 'story_points' => 15]);

    // Epic planned for only squad1
    $uniqueEpic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create(['title' => 'Unique Epic']);
    $uniqueEpic->squads()->attach($squad1);
    EpicQuarterPlan::create(['epic_id' => $uniqueEpic->id, 'squad_id' => $squad1->id, 'quarter' => 'Q1-2026', 'story_points' => 20]);

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

    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $squad1->id,
        'quarter' => 'Q1-2026',
    ]);
    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $squad2->id,
        'quarter' => 'Q1-2026',
    ]);
});

it('maintains independent story points per squad', function () {
    $squad1 = Squad::factory()->for($this->team)->create(['name' => 'Squad 1']);
    $squad2 = Squad::factory()->for($this->team)->create(['name' => 'Squad 2']);

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create();
    $epic->squads()->attach([$squad1->id, $squad2->id]);
    EpicQuarterPlan::create(['epic_id' => $epic->id, 'squad_id' => $squad1->id, 'quarter' => 'Q1-2026', 'story_points' => 10]);
    EpicQuarterPlan::create(['epic_id' => $epic->id, 'squad_id' => $squad2->id, 'quarter' => 'Q1-2026', 'story_points' => 20]);

    // Update only squad1's points
    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$squad1->id, $squad2->id])
        ->call('updateEpicStoryPoints', $epic->id, $squad1->id, 15);

    // Squad 1's points updated
    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $squad1->id,
        'story_points' => 15,
    ]);
    // Squad 2's points unchanged
    $this->assertDatabaseHas('epic_quarter_plans', [
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
    $epic1->squads()->attach($squad1);
    EpicQuarterPlan::create(['epic_id' => $epic1->id, 'squad_id' => $squad1->id, 'quarter' => 'Q1-2026', 'story_points' => 30]);

    $epic2 = Epic::factory()->for($this->team)->for($this->status)->create();
    $epic2->squads()->attach($squad2);
    EpicQuarterPlan::create(['epic_id' => $epic2->id, 'squad_id' => $squad2->id, 'quarter' => 'Q1-2026', 'story_points' => 50]);

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
    $partiallyPlannedEpic->squads()->attach([$squad1->id, $squad2->id]);
    EpicQuarterPlan::create(['epic_id' => $partiallyPlannedEpic->id, 'squad_id' => $squad1->id, 'quarter' => 'Q1-2026', 'story_points' => 10]);
    // Not planned for squad2

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

// Category Tests

it('loads categories for the team', function () {
    $category = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);

    $component = Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id]);

    $categories = $component->viewData('categories');

    expect($categories)->toHaveCount(1)
        ->and($categories->first()->name)->toBe('Growth');
});

it('groups planned epics by category', function () {
    $category1 = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);
    $category2 = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Tech']);

    $epic1 = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->for($category1)
        ->create(['title' => 'Growth Epic']);
    $epic1->squads()->attach($this->squad);
    EpicQuarterPlan::create(['epic_id' => $epic1->id, 'squad_id' => $this->squad->id, 'quarter' => 'Q1-2026', 'story_points' => 20]);

    $epic2 = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->for($category2)
        ->create(['title' => 'Tech Epic']);
    $epic2->squads()->attach($this->squad);
    EpicQuarterPlan::create(['epic_id' => $epic2->id, 'squad_id' => $this->squad->id, 'quarter' => 'Q1-2026', 'story_points' => 15]);

    $component = Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id]);

    $squadData = $component->viewData('squadData');
    $plannedEpics = $squadData[$this->squad->id]['planned_epics'];

    // Verify both epics are in planned epics
    expect($plannedEpics)->toHaveCount(2);

    // Verify category relationships are loaded
    $growthEpic = $plannedEpics->firstWhere('title', 'Growth Epic');
    $techEpic = $plannedEpics->firstWhere('title', 'Tech Epic');

    expect($growthEpic->category_id)->toBe($category1->id)
        ->and($techEpic->category_id)->toBe($category2->id);
});

it('calculates actual points by category', function () {
    $category1 = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);
    $category2 = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Tech']);

    $epic1 = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->for($category1)
        ->create();
    $epic1->squads()->attach($this->squad);
    EpicQuarterPlan::create(['epic_id' => $epic1->id, 'squad_id' => $this->squad->id, 'quarter' => 'Q1-2026', 'story_points' => 25]);

    $epic2 = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->for($category1)
        ->create();
    $epic2->squads()->attach($this->squad);
    EpicQuarterPlan::create(['epic_id' => $epic2->id, 'squad_id' => $this->squad->id, 'quarter' => 'Q1-2026', 'story_points' => 15]);

    $epic3 = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->for($category2)
        ->create();
    $epic3->squads()->attach($this->squad);
    EpicQuarterPlan::create(['epic_id' => $epic3->id, 'squad_id' => $this->squad->id, 'quarter' => 'Q1-2026', 'story_points' => 30]);

    $component = Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id]);

    $squadData = $component->viewData('squadData');
    $actualByCategory = $squadData[$this->squad->id]['actual_by_category'];

    expect($actualByCategory[$category1->id])->toBe(40) // 25 + 15
        ->and($actualByCategory[$category2->id])->toBe(30);
});

it('can set category targets for a quarter plan', function () {
    $category = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->set('editingCapacityValues.'.$this->squad->id, 100)
        ->call('saveCapacityForSquad', $this->squad->id)
        ->call('saveCategoryTarget', $this->squad->id, $category->id, 50);

    $quarterPlan = QuarterPlan::where('squad_id', $this->squad->id)
        ->where('year', 2026)
        ->where('quarter', 1)
        ->first();

    expect($quarterPlan)->not->toBeNull();

    $categoryPivot = $quarterPlan->categories()->where('category_id', $category->id)->first();
    expect($categoryPivot)->not->toBeNull()
        ->and($categoryPivot->pivot->allocated_story_points)->toBe(50);
});

it('loads category targets when loading quarter plans', function () {
    $category = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);

    $quarterPlan = QuarterPlan::factory()
        ->for($this->team)
        ->for($this->squad)
        ->create([
            'year' => 2026,
            'quarter' => 1,
            'available_story_points' => 100,
        ]);

    $quarterPlan->categories()->attach($category->id, ['allocated_story_points' => 40]);

    $component = Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id]);

    $categoryTargets = $component->viewData('categoryTargets');

    expect($categoryTargets[$this->squad->id][$category->id])->toBe(40);
});

it('can update epic from popover including title', function () {
    $category = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);
    $newStatus = Status::skip(1)->first();

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create([
            'title' => 'Original Title',
            'description' => 'Original description',
        ]);
    $epic->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => 20,
    ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('updateEpicFromPopover', $epic->id, $this->squad->id, 'New Title', 25, $newStatus->id, $category->id, 'New description');

    $epic->refresh();

    expect($epic->title)->toBe('New Title')
        ->and($epic->description)->toBe('New description')
        ->and($epic->status_id)->toBe($newStatus->id);

    // Category is now per-plan, so check on quarter plan instead of epic
    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => 25,
        'category_id' => $category->id,
    ]);
});

it('can clear epic category via popover', function () {
    $category = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->for($category)
        ->create(['title' => 'Epic with category']);
    $epic->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => 20,
        'category_id' => $category->id,
    ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('updateEpicFromPopover', $epic->id, $this->squad->id, 'Epic with category', 20, $this->status->id, null, null);

    // Category is now per-plan, so check on quarter plan
    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'category_id' => null,
    ]);
});

// Sort Handler Tests

it('can sort epic within same category column', function () {
    $category = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);

    $epic1 = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->for($category)
        ->create(['title' => 'Epic 1']);
    $epic1->squads()->attach($this->squad);
    EpicQuarterPlan::create(['epic_id' => $epic1->id, 'squad_id' => $this->squad->id, 'quarter' => 'Q1-2026', 'story_points' => 10]);

    $epic2 = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->for($category)
        ->create(['title' => 'Epic 2']);
    $epic2->squads()->attach($this->squad);
    EpicQuarterPlan::create(['epic_id' => $epic2->id, 'squad_id' => $this->squad->id, 'quarter' => 'Q1-2026', 'story_points' => 15]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('sort', $epic2->id, 0, $category->id, $this->squad->id);

    // Epic2 should now have sort_order 0
    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic2->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'sort_order' => 0,
    ]);
});

it('can move epic from backlog to category column', function () {
    $category = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create([
            'title' => 'Backlog Epic',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);
    $epic->squads()->attach($this->squad); // Not planned yet

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('sort', $epic->id, 0, $category->id, $this->squad->id);

    // Category is now per-plan, so check on quarter plan
    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'category_id' => $category->id,
    ]);
});

it('can move epic between category columns', function () {
    $category1 = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);
    $category2 = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Tech']);

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->for($category1)
        ->create(['title' => 'Epic to move']);
    $epic->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => 20,
        'category_id' => $category1->id,
    ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('sort', $epic->id, 0, $category2->id, $this->squad->id);

    // Category is now per-plan, so check on quarter plan
    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'category_id' => $category2->id,
    ]);
});

it('can move epic from category column to other-backlog', function () {
    $category = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->for($category)
        ->create(['title' => 'Planned Epic']);
    $epic->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => 20,
        'category_id' => $category->id,
    ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('sort', $epic->id, 0, 'other-backlog', $this->squad->id);

    // Quarter plan record should be deleted
    $this->assertDatabaseMissing('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
    ]);
});

it('can move epic to uncategorized column', function () {
    $category = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->for($category)
        ->create(['title' => 'Categorized Epic']);
    $epic->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => 20,
        'category_id' => $category->id,
    ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('sort', $epic->id, 0, 'uncategorized', $this->squad->id);

    // Category is now per-plan, so check on quarter plan
    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'category_id' => null,
    ]);
});

it('cannot sort epic from another team', function () {
    $otherTeam = Team::factory()->create();
    $category = \App\Models\Category::factory()->for($this->team)->create();

    $otherEpic = Epic::factory()
        ->for($otherTeam)
        ->for($this->status)
        ->create();
    $otherEpic->squads()->attach($this->squad);

    // This should silently fail (epic not found for team)
    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('sort', $otherEpic->id, 0, $category->id, $this->squad->id);

    // Epic should NOT be planned
    $this->assertDatabaseMissing('epic_quarter_plans', [
        'epic_id' => $otherEpic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
    ]);
});

it('sort creates new quarter entry with null story points', function () {
    $category = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create([
            'title' => 'Epic with points',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);
    // Attach squad (no quarter plan yet - backlog)
    $epic->squads()->attach($this->squad);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('sort', $epic->id, 0, $category->id, $this->squad->id);

    // New quarter entry should have null story points (fresh estimate)
    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => null,
    ]);
});

// Multi-Quarter Epic Tests

it('epic can exist in multiple quarters with independent story points', function () {
    $category = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->for($category)
        ->create(['title' => 'Carryover Epic']);

    // Assign squad and add to Q4-2025 with 30 points
    $epic->squads()->attach($this->squad);
    EpicQuarterPlan::create(['epic_id' => $epic->id, 'squad_id' => $this->squad->id, 'quarter' => 'Q4-2025', 'story_points' => 30]);

    // Add to Q1-2026 via sort (simulating drag from backlog)
    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('sort', $epic->id, 0, $category->id, $this->squad->id);

    // Both quarter entries should exist
    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q4-2025',
        'story_points' => 30,
    ]);

    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => null,  // Fresh estimate for new quarter
    ]);
});

it('removing epic from one quarter does not affect other quarters', function () {
    $category = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->for($category)
        ->create(['title' => 'Multi-Quarter Epic']);

    // Assign squad and plan in both Q4-2025 and Q1-2026
    $epic->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q4-2025',
        'story_points' => 30,
    ]);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => 12,
    ]);

    // Remove from Q1-2026
    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('removeEpicFromPlan', $epic->id, $this->squad->id);

    // Q1-2026 entry should be deleted
    $this->assertDatabaseMissing('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
    ]);

    // Q4-2025 entry should still exist with original points
    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q4-2025',
        'story_points' => 30,
    ]);
});

it('updating story points in one quarter does not affect other quarters', function () {
    $category = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->for($category)
        ->create(['title' => 'Multi-Quarter Epic']);

    // Assign squad and plan in both quarters
    $epic->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q4-2025',
        'story_points' => 30,
    ]);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => 12,
    ]);

    // Update Q1-2026 story points
    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('updateEpicStoryPoints', $epic->id, $this->squad->id, 15);

    // Q1-2026 should be updated
    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => 15,
    ]);

    // Q4-2025 should be unchanged
    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q4-2025',
        'story_points' => 30,
    ]);
});

it('capacity calculations are independent per quarter', function () {
    $category = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->for($category)
        ->create(['title' => 'Multi-Quarter Epic']);

    // Assign squad and plan: 30 pts in Q4-2025, 12 pts in Q1-2026
    $epic->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q4-2025',
        'story_points' => 30,
    ]);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => 12,
    ]);

    // Check Q1-2026 shows only 12 pts
    $component = Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id]);

    $squadData = $component->viewData('squadData');
    expect($squadData[$this->squad->id]['allocated'])->toBe(12);
});

it('epic shows in backlog if not planned for current quarter but planned for another', function () {
    $category = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->for($category)
        ->create([
            'title' => 'Q4 Only Epic',
            'start_date' => '2025-10-01',
            'end_date' => '2026-03-31',
        ]);

    // Assign squad and plan only for Q4-2025
    $epic->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q4-2025',
        'story_points' => 30,
    ]);

    // When viewing Q1-2026, epic should appear in backlog
    $component = Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id]);

    $squadData = $component->viewData('squadData');
    $backlogData = $squadData[$this->squad->id]['backlog_epics'];

    // backlog_epics is now an array with 'considering' and 'other' keys
    $allBacklogIds = $backlogData['considering']->pluck('id')
        ->merge($backlogData['other']->pluck('id'))
        ->toArray();

    expect($allBacklogIds)->toContain($epic->id);
});

it('can clear story points to null via inline update', function () {
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create();
    $epic->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => 20,
    ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('updateEpicStoryPoints', $epic->id, $this->squad->id, null);

    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => null,
    ]);
});

it('can clear story points to null via popover using empty string', function () {
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create(['title' => 'Test Epic']);
    $epic->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => 20,
    ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('updateEpicFromPopover', $epic->id, $this->squad->id, 'Test Epic', '', $this->status->id, null, null);

    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => null,
    ]);
});

it('updateEpicFromPopover updates story points for correct quarter only', function () {
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create(['title' => 'Multi-Quarter Epic']);

    // Assign squad and plan in both Q4-2025 and Q1-2026
    $epic->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q4-2025',
        'story_points' => 30,
    ]);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => 12,
    ]);

    // Update via popover while viewing Q1-2026
    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('updateEpicFromPopover', $epic->id, $this->squad->id, 'Multi-Quarter Epic', 18, $this->status->id, null, null);

    // Q1-2026 should be updated
    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => 18,
    ]);

    // Q4-2025 should be unchanged
    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q4-2025',
        'story_points' => 30,
    ]);
});

it('epic disappears from backlog after being added to plan via sort', function () {
    // Epic is in backlog (has squad assignment but not planned for this quarter)
    // Dates must overlap Q1-2026 (Jan 1 - Mar 31) to appear in backlog
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create([
            'start_date' => '2026-01-15',
            'end_date' => '2026-03-15',
        ]);

    // Assign squad and plan in Q4-2025 but NOT in Q1-2026
    $epic->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q4-2025',
        'story_points' => 20,
    ]);

    $component = Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id]);

    // Verify epic is in backlog initially
    $squadData = $component->viewData('squadData');
    $backlogData = $squadData[$this->squad->id]['backlog_epics'];
    $allBacklogIds = $backlogData['considering']->pluck('id')
        ->merge($backlogData['other']->pluck('id'))
        ->toArray();
    expect($allBacklogIds)->toContain($epic->id);

    // Drag from backlog to planned (category column)
    $component->call('sort', $epic->id, 0, 'uncategorized', $this->squad->id);

    // Re-fetch view data after sort
    $squadData = $component->viewData('squadData');
    $backlogData = $squadData[$this->squad->id]['backlog_epics'];
    $allBacklogIds = $backlogData['considering']->pluck('id')
        ->merge($backlogData['other']->pluck('id'))
        ->toArray();
    $plannedEpics = $squadData[$this->squad->id]['planned_epics'];

    // Epic should NOT be in backlog anymore
    expect($allBacklogIds)->not->toContain($epic->id);

    // Epic should be in planned
    expect($plannedEpics->pluck('id')->toArray())->toContain($epic->id);

    // Both quarter plans should exist
    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q4-2025',
        'story_points' => 20,
    ]);
    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
    ]);
});

it('epic removed from plan stays in backlog if has other quarter plan', function () {
    // Dates must overlap Q1-2026 (Jan 1 - Mar 31) to appear in backlog
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create([
            'start_date' => '2026-01-15',
            'end_date' => '2026-03-15',
        ]);

    // Assign squad and plan in BOTH Q4-2025 and Q1-2026
    $epic->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q4-2025',
        'story_points' => 20,
    ]);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => 15,
    ]);

    $component = Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id]);

    // Verify epic is in planned initially
    $squadData = $component->viewData('squadData');
    $plannedEpics = $squadData[$this->squad->id]['planned_epics'];
    expect($plannedEpics->pluck('id')->toArray())->toContain($epic->id);

    // Remove from Q1-2026 plan (drag to other-backlog)
    $component->call('sort', $epic->id, 0, 'other-backlog', $this->squad->id);

    // Re-fetch view data
    $squadData = $component->viewData('squadData');
    $backlogData = $squadData[$this->squad->id]['backlog_epics'];
    $allBacklogIds = $backlogData['considering']->pluck('id')
        ->merge($backlogData['other']->pluck('id'))
        ->toArray();
    $plannedEpics = $squadData[$this->squad->id]['planned_epics'];

    // Epic should be in backlog now (still assigned to squad)
    expect($allBacklogIds)->toContain($epic->id);

    // Epic should NOT be in planned anymore for Q1-2026
    expect($plannedEpics->pluck('id')->toArray())->not->toContain($epic->id);

    // Q1-2026 plan should be deleted
    $this->assertDatabaseMissing('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
    ]);

    // Q4-2025 plan should still exist
    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q4-2025',
        'story_points' => 20,
    ]);
});

// Backlog Consideration Tests

it('can toggle epic consideration for backlog', function () {
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create([
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);
    $epic->squads()->attach($this->squad);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('toggleConsideration', $epic->id, $this->squad->id);

    $this->assertDatabaseHas('backlog_considerations', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
    ]);
});

it('can remove epic from consideration', function () {
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create([
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);
    $epic->squads()->attach($this->squad);

    // First add to consideration
    \App\Models\BacklogConsideration::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
    ]);

    // Then toggle to remove
    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('toggleConsideration', $epic->id, $this->squad->id);

    $this->assertDatabaseMissing('backlog_considerations', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
    ]);
});

it('backlog epics are split into considering and other', function () {
    $epic1 = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create([
            'title' => 'Considering Epic',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);
    $epic1->squads()->attach($this->squad);

    $epic2 = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create([
            'title' => 'Other Epic',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);
    $epic2->squads()->attach($this->squad);

    // Mark epic1 as considering
    \App\Models\BacklogConsideration::create([
        'epic_id' => $epic1->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
    ]);

    $component = Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id]);

    $squadData = $component->viewData('squadData');
    $backlogData = $squadData[$this->squad->id]['backlog_epics'];

    expect($backlogData)->toBeArray()
        ->and($backlogData['considering'])->toHaveCount(1)
        ->and($backlogData['considering']->first()->id)->toBe($epic1->id)
        ->and($backlogData['other'])->toHaveCount(1)
        ->and($backlogData['other']->first()->id)->toBe($epic2->id);
});

it('consideration is per-quarter', function () {
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create([
            'start_date' => '2025-10-01',
            'end_date' => '2026-06-30',
        ]);
    $epic->squads()->attach($this->squad);

    // Mark as considering for Q1-2026
    \App\Models\BacklogConsideration::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
    ]);

    // Check Q1-2026 - should be in considering
    $component = Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id]);

    $squadData = $component->viewData('squadData');
    $backlogData = $squadData[$this->squad->id]['backlog_epics'];
    expect($backlogData['considering']->pluck('id')->toArray())->toContain($epic->id);

    // Check Q2-2026 - should NOT be in considering (different quarter)
    $component2 = Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q2-2026')
        ->set('selectedSquadIds', [$this->squad->id]);

    $squadData2 = $component2->viewData('squadData');
    $backlogData2 = $squadData2[$this->squad->id]['backlog_epics'];
    expect($backlogData2['considering']->pluck('id')->toArray())->not->toContain($epic->id)
        ->and($backlogData2['other']->pluck('id')->toArray())->toContain($epic->id);
});

it('can drag epic from other-backlog to considering', function () {
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create([
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);
    $epic->squads()->attach($this->squad);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('sort', $epic->id, 0, 'considering', $this->squad->id);

    $this->assertDatabaseHas('backlog_considerations', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
    ]);
});

it('can drag epic from considering to other-backlog', function () {
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create([
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);
    $epic->squads()->attach($this->squad);

    // Start with epic in considering
    \App\Models\BacklogConsideration::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
    ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('sort', $epic->id, 0, 'other-backlog', $this->squad->id);

    $this->assertDatabaseMissing('backlog_considerations', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
    ]);
});

it('can drag epic from considering directly to category column', function () {
    $category = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create([
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);
    $epic->squads()->attach($this->squad);

    // Start with epic in considering
    \App\Models\BacklogConsideration::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
    ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('sort', $epic->id, 0, $category->id, $this->squad->id);

    // Epic should be planned
    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'category_id' => $category->id,
    ]);
});

it('dragging planned epic to considering removes from plan and adds to considering', function () {
    $category = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create();
    $epic->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'category_id' => $category->id,
        'story_points' => 20,
    ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('sort', $epic->id, 0, 'considering', $this->squad->id);

    // Should be removed from plan
    $this->assertDatabaseMissing('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
    ]);

    // Should be in considering
    $this->assertDatabaseHas('backlog_considerations', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
    ]);
});

it('dragging planned epic to other-backlog removes from plan and not in considering', function () {
    $category = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create();
    $epic->squads()->attach($this->squad);
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'category_id' => $category->id,
        'story_points' => 20,
    ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('sort', $epic->id, 0, 'other-backlog', $this->squad->id);

    // Should be removed from plan
    $this->assertDatabaseMissing('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
    ]);

    // Should NOT be in considering
    $this->assertDatabaseMissing('backlog_considerations', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
    ]);
});

it('cannot toggle consideration for epic from another team', function () {
    $otherTeam = Team::factory()->create();
    $otherEpic = Epic::factory()
        ->for($otherTeam)
        ->for($this->status)
        ->create();

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('toggleConsideration', $otherEpic->id, $this->squad->id)
        ->assertForbidden();
});

it('can edit backlog epic via popover and creates quarter plan', function () {
    $category = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create([
            'title' => 'Backlog Epic',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);
    $epic->squads()->attach($this->squad);

    // Verify no quarter plan exists initially
    $this->assertDatabaseMissing('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
    ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('updateEpicFromPopover', $epic->id, $this->squad->id, 'Updated Title', 15, $this->status->id, $category->id, 'New description');

    $epic->refresh();

    // Epic fields should be updated
    expect($epic->title)->toBe('Updated Title')
        ->and($epic->description)->toBe('New description');

    // Quarter plan should now exist with category and story points
    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => 15,
        'category_id' => $category->id,
    ]);
});
