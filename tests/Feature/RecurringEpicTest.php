<?php

use App\Models\Category;
use App\Models\Epic;
use App\Models\EpicQuarterPlan;
use App\Models\QuarterPlan;
use App\Models\Squad;
use App\Models\Status;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(\Database\Seeders\StatusSeeder::class);

    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->squad = Squad::factory()->for($this->team)->create();
    $this->status = Status::first();

    $this->actingAs($this->user);
});

it('can create a recurring epic', function () {
    Livewire::test('epics.create')
        ->set('title', 'Requests from other squads')
        ->set('status_id', $this->status->id)
        ->set('is_recurring', true)
        ->set('squad_ids', [$this->squad->id])
        ->call('save')
        ->assertRedirect('/epics');

    $this->assertDatabaseHas('epics', [
        'team_id' => $this->team->id,
        'title' => 'Requests from other squads',
        'is_recurring' => true,
    ]);
});

it('defaults is_recurring to false', function () {
    Livewire::test('epics.create')
        ->set('title', 'Normal epic')
        ->set('status_id', $this->status->id)
        ->set('squad_ids', [$this->squad->id])
        ->call('save')
        ->assertRedirect('/epics');

    $this->assertDatabaseHas('epics', [
        'team_id' => $this->team->id,
        'title' => 'Normal epic',
        'is_recurring' => false,
    ]);
});

it('can toggle is_recurring on an existing epic', function () {
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create(['is_recurring' => false]);
    $epic->squads()->attach($this->squad);

    Livewire::test('epics.edit', ['epic' => $epic])
        ->set('is_recurring', true)
        ->call('save')
        ->assertRedirect('/epics');

    $epic->refresh();
    expect($epic->is_recurring)->toBeTrue();
});

it('tracks is_recurring in dirty checking', function () {
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create(['is_recurring' => false]);
    $epic->squads()->attach($this->squad);

    $component = Livewire::test('epics.edit', ['epic' => $epic]);

    // Initially no unsaved changes
    expect($component->call('hasUnsavedChanges')->get('__testing'))->toBeFalsy();

    // Toggle recurring — should have unsaved changes
    $component->set('is_recurring', true);
    expect($component->instance()->hasUnsavedChanges())->toBeTrue();

    // Discard changes — should revert
    $component->call('discardChanges');
    expect($component->instance()->is_recurring)->toBeFalse();
    expect($component->instance()->hasUnsavedChanges())->toBeFalse();
});

it('auto-adds recurring epics when a new quarter plan is created', function () {
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create(['is_recurring' => true]);
    $epic->squads()->attach($this->squad->id, [
        'estimated_story_points' => 40,
    ]);

    // Navigate to a quarter — this triggers saveAllPlans() which creates the QuarterPlan
    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q3-2026')
        ->set('selectedSquadIds', [$this->squad->id]);

    // The recurring epic should have been auto-added to the quarter plan
    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q3-2026',
        'story_points' => 40,
    ]);
});

it('does not auto-add non-recurring epics when a quarter plan is created', function () {
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create(['is_recurring' => false]);
    $epic->squads()->attach($this->squad->id, [
        'estimated_story_points' => 40,
    ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q3-2026')
        ->set('selectedSquadIds', [$this->squad->id]);

    $this->assertDatabaseMissing('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q3-2026',
    ]);
});

it('does not duplicate recurring epics on subsequent visits to the same quarter', function () {
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create(['is_recurring' => true]);
    $epic->squads()->attach($this->squad->id, [
        'estimated_story_points' => 30,
    ]);

    // First visit — creates the QuarterPlan and auto-adds the recurring epic
    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q3-2026')
        ->set('selectedSquadIds', [$this->squad->id]);

    // Second visit — QuarterPlan already exists, should not duplicate
    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q3-2026')
        ->set('selectedSquadIds', [$this->squad->id]);

    $count = EpicQuarterPlan::where('epic_id', $epic->id)
        ->where('squad_id', $this->squad->id)
        ->where('quarter', 'Q3-2026')
        ->count();

    expect($count)->toBe(1);
});

it('auto-adds recurring epics to multiple squads independently', function () {
    $squad2 = Squad::factory()->for($this->team)->create();

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create(['is_recurring' => true]);
    $epic->squads()->attach([
        $this->squad->id => ['estimated_story_points' => 20],
        $squad2->id => ['estimated_story_points' => 50],
    ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q2-2026')
        ->set('selectedSquadIds', [$this->squad->id, $squad2->id]);

    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q2-2026',
        'story_points' => 20,
    ]);

    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $squad2->id,
        'quarter' => 'Q2-2026',
        'story_points' => 50,
    ]);
});

it('uses epic category_id when auto-adding recurring epics', function () {
    $category = Category::factory()->for($this->team)->create();

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->for($category)
        ->create(['is_recurring' => true]);
    $epic->squads()->attach($this->squad->id, [
        'estimated_story_points' => 15,
    ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q4-2026')
        ->set('selectedSquadIds', [$this->squad->id]);

    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q4-2026',
        'category_id' => $category->id,
    ]);
});

it('handles recurring epics with no estimated story points', function () {
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create(['is_recurring' => true]);
    $epic->squads()->attach($this->squad->id, [
        'estimated_story_points' => null,
    ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q3-2026')
        ->set('selectedSquadIds', [$this->squad->id]);

    $this->assertDatabaseHas('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q3-2026',
        'story_points' => null,
    ]);
});

it('does not auto-add recurring epics from another team', function () {
    $otherTeam = \App\Models\Team::factory()->create();
    $otherSquad = Squad::factory()->for($otherTeam)->create();

    $epic = Epic::factory()
        ->for($otherTeam)
        ->for($this->status)
        ->create(['is_recurring' => true]);
    $epic->squads()->attach($otherSquad->id, [
        'estimated_story_points' => 30,
    ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q3-2026')
        ->set('selectedSquadIds', [$this->squad->id]);

    $this->assertDatabaseMissing('epic_quarter_plans', [
        'epic_id' => $epic->id,
    ]);
});

it('can remove a recurring epic from a specific quarter plan', function () {
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create(['is_recurring' => true]);
    $epic->squads()->attach($this->squad->id, [
        'estimated_story_points' => 25,
    ]);

    // Create the quarter plan entry
    EpicQuarterPlan::create([
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
        'story_points' => 25,
    ]);

    Livewire::test('planning.show', ['plan' => null])
        ->set('selectedQuarter', 'Q1-2026')
        ->set('selectedSquadIds', [$this->squad->id])
        ->call('removeEpicFromPlan', $epic->id, $this->squad->id);

    $this->assertDatabaseMissing('epic_quarter_plans', [
        'epic_id' => $epic->id,
        'squad_id' => $this->squad->id,
        'quarter' => 'Q1-2026',
    ]);
});
