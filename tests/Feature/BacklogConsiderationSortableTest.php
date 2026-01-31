<?php

use App\Models\BacklogConsideration;
use App\Models\Epic;
use App\Models\EpicQuarterPlan;
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

describe('BacklogConsideration Sortable Trait', function () {
    it('auto assigns sort_order on creation', function () {
        $epic1 = Epic::factory()->for($this->team)->for($this->status)->create();
        $epic1->squads()->attach($this->squad);
        BacklogConsideration::create([
            'epic_id' => $epic1->id,
            'squad_id' => $this->squad->id,
            'quarter' => 'Q1-2026',
        ]);

        $epic2 = Epic::factory()->for($this->team)->for($this->status)->create();
        $epic2->squads()->attach($this->squad);
        BacklogConsideration::create([
            'epic_id' => $epic2->id,
            'squad_id' => $this->squad->id,
            'quarter' => 'Q1-2026',
        ]);

        $epic3 = Epic::factory()->for($this->team)->for($this->status)->create();
        $epic3->squads()->attach($this->squad);
        BacklogConsideration::create([
            'epic_id' => $epic3->id,
            'squad_id' => $this->squad->id,
            'quarter' => 'Q1-2026',
        ]);

        $considerations = BacklogConsideration::where('squad_id', $this->squad->id)
            ->where('quarter', 'Q1-2026')
            ->orderBy('sort_order')
            ->get();

        expect($considerations)->toHaveCount(3)
            ->and($considerations[0]->sort_order)->toBe(0)
            ->and($considerations[1]->sort_order)->toBe(1)
            ->and($considerations[2]->sort_order)->toBe(2);
    });

    it('scopes sort_order by squad and quarter', function () {
        $squad2 = Squad::factory()->for($this->team)->create();

        // Consideration for squad1, Q1-2026
        $epic1 = Epic::factory()->for($this->team)->for($this->status)->create();
        $epic1->squads()->attach($this->squad);
        BacklogConsideration::create([
            'epic_id' => $epic1->id,
            'squad_id' => $this->squad->id,
            'quarter' => 'Q1-2026',
        ]);

        // Consideration for squad2, Q1-2026 (should have independent ordering)
        $epic2 = Epic::factory()->for($this->team)->for($this->status)->create();
        $epic2->squads()->attach($squad2);
        BacklogConsideration::create([
            'epic_id' => $epic2->id,
            'squad_id' => $squad2->id,
            'quarter' => 'Q1-2026',
        ]);

        // Consideration for squad1, Q2-2026 (should have independent ordering)
        $epic3 = Epic::factory()->for($this->team)->for($this->status)->create();
        $epic3->squads()->attach($this->squad);
        BacklogConsideration::create([
            'epic_id' => $epic3->id,
            'squad_id' => $this->squad->id,
            'quarter' => 'Q2-2026',
        ]);

        $consideration1 = BacklogConsideration::where('epic_id', $epic1->id)->first();
        $consideration2 = BacklogConsideration::where('epic_id', $epic2->id)->first();
        $consideration3 = BacklogConsideration::where('epic_id', $epic3->id)->first();

        // Each should start at 0 because they're in different scopes
        expect($consideration1->sort_order)->toBe(0)
            ->and($consideration2->sort_order)->toBe(0)
            ->and($consideration3->sort_order)->toBe(0);
    });

    it('can move item to new position', function () {
        $epics = [];
        for ($i = 0; $i < 4; $i++) {
            $epic = Epic::factory()->for($this->team)->for($this->status)->create(['title' => "Epic $i"]);
            $epic->squads()->attach($this->squad);
            BacklogConsideration::create([
                'epic_id' => $epic->id,
                'squad_id' => $this->squad->id,
                'quarter' => 'Q1-2026',
            ]);
            $epics[] = $epic;
        }

        // Initially: 0, 1, 2, 3
        // Move epic3 (position 3) to position 1
        $consideration3 = BacklogConsideration::where('epic_id', $epics[3]->id)->first();
        $consideration3->move(1);

        // Fetch updated positions
        $updatedPositions = [];
        foreach ($epics as $epic) {
            $updatedPositions[$epic->id] = BacklogConsideration::where('epic_id', $epic->id)->first()->sort_order;
        }

        // After move: 0 stays at 0, 3 moves to 1, 1 shifts to 2, 2 shifts to 3
        expect($updatedPositions[$epics[0]->id])->toBe(0)
            ->and($updatedPositions[$epics[3]->id])->toBe(1)
            ->and($updatedPositions[$epics[1]->id])->toBe(2)
            ->and($updatedPositions[$epics[2]->id])->toBe(3);
    });

    it('can move item forward', function () {
        $epics = [];
        for ($i = 0; $i < 4; $i++) {
            $epic = Epic::factory()->for($this->team)->for($this->status)->create(['title' => "Epic $i"]);
            $epic->squads()->attach($this->squad);
            BacklogConsideration::create([
                'epic_id' => $epic->id,
                'squad_id' => $this->squad->id,
                'quarter' => 'Q1-2026',
            ]);
            $epics[] = $epic;
        }

        // Initially: 0, 1, 2, 3
        // Move epic0 (position 0) to position 2
        $consideration0 = BacklogConsideration::where('epic_id', $epics[0]->id)->first();
        $consideration0->move(2);

        // Fetch updated positions
        $updatedPositions = [];
        foreach ($epics as $epic) {
            $updatedPositions[$epic->id] = BacklogConsideration::where('epic_id', $epic->id)->first()->sort_order;
        }

        // After move: 1 shifts to 0, 2 shifts to 1, 0 moves to 2, 3 stays at 3
        expect($updatedPositions[$epics[1]->id])->toBe(0)
            ->and($updatedPositions[$epics[2]->id])->toBe(1)
            ->and($updatedPositions[$epics[0]->id])->toBe(2)
            ->and($updatedPositions[$epics[3]->id])->toBe(3);
    });

    it('move to same position is a no-op', function () {
        $epic = Epic::factory()->for($this->team)->for($this->status)->create();
        $epic->squads()->attach($this->squad);
        BacklogConsideration::create([
            'epic_id' => $epic->id,
            'squad_id' => $this->squad->id,
            'quarter' => 'Q1-2026',
        ]);

        $consideration = BacklogConsideration::where('epic_id', $epic->id)->first();
        $originalSortOrder = $consideration->sort_order;

        $consideration->move($originalSortOrder);

        $consideration->refresh();
        expect($consideration->sort_order)->toBe($originalSortOrder);
    });

    it('orders by sort_order by default via global scope', function () {
        // Create epics in random order
        $epic3 = Epic::factory()->for($this->team)->for($this->status)->create(['title' => 'Epic C']);
        $epic3->squads()->attach($this->squad);
        BacklogConsideration::create([
            'epic_id' => $epic3->id,
            'squad_id' => $this->squad->id,
            'quarter' => 'Q1-2026',
        ]);

        $epic1 = Epic::factory()->for($this->team)->for($this->status)->create(['title' => 'Epic A']);
        $epic1->squads()->attach($this->squad);
        BacklogConsideration::create([
            'epic_id' => $epic1->id,
            'squad_id' => $this->squad->id,
            'quarter' => 'Q1-2026',
        ]);

        $epic2 = Epic::factory()->for($this->team)->for($this->status)->create(['title' => 'Epic B']);
        $epic2->squads()->attach($this->squad);
        BacklogConsideration::create([
            'epic_id' => $epic2->id,
            'squad_id' => $this->squad->id,
            'quarter' => 'Q1-2026',
        ]);

        // Move epic1 to position 0
        $consideration1 = BacklogConsideration::where('epic_id', $epic1->id)->first();
        $consideration1->move(0);

        // Query should return in sort_order
        $considerations = BacklogConsideration::where('squad_id', $this->squad->id)
            ->where('quarter', 'Q1-2026')
            ->get();

        // epic1 should be first (sort_order 0)
        expect($considerations->first()->epic_id)->toBe($epic1->id);
    });

    it('deleting consideration updates other positions', function () {
        $epics = [];
        for ($i = 0; $i < 3; $i++) {
            $epic = Epic::factory()->for($this->team)->for($this->status)->create(['title' => "Epic $i"]);
            $epic->squads()->attach($this->squad);
            BacklogConsideration::create([
                'epic_id' => $epic->id,
                'squad_id' => $this->squad->id,
                'quarter' => 'Q1-2026',
            ]);
            $epics[] = $epic;
        }

        // Delete the middle consideration
        $consideration1 = BacklogConsideration::where('epic_id', $epics[1]->id)->first();
        $consideration1->delete();

        // Remaining items should still have valid sort_order
        $remaining = BacklogConsideration::where('squad_id', $this->squad->id)
            ->where('quarter', 'Q1-2026')
            ->get();

        expect($remaining)->toHaveCount(2);
    });
});

describe('Planning Component - Considering Sort Integration', function () {
    it('can reorder epics within considering section via sort method', function () {
        // Create 3 epics in considering
        $epics = [];
        for ($i = 0; $i < 3; $i++) {
            $epic = Epic::factory()->for($this->team)->for($this->status)->create([
                'title' => "Epic $i",
                'start_date' => '2026-01-01',
                'end_date' => '2026-03-31',
            ]);
            $epic->squads()->attach($this->squad);
            BacklogConsideration::create([
                'epic_id' => $epic->id,
                'squad_id' => $this->squad->id,
                'quarter' => 'Q1-2026',
            ]);
            $epics[] = $epic;
        }

        // Move epic2 (position 2) to position 0
        Livewire::test('planning.show', ['plan' => null])
            ->set('selectedQuarter', 'Q1-2026')
            ->set('selectedSquadIds', [$this->squad->id])
            ->call('sort', $epics[2]->id, 0, 'considering', $this->squad->id);

        // Verify the new order
        $considerations = BacklogConsideration::where('squad_id', $this->squad->id)
            ->where('quarter', 'Q1-2026')
            ->get();

        expect($considerations[0]->epic_id)->toBe($epics[2]->id)
            ->and($considerations[1]->epic_id)->toBe($epics[0]->id)
            ->and($considerations[2]->epic_id)->toBe($epics[1]->id);
    });

    it('dragging from other-backlog to considering places at correct position', function () {
        // Create epic in other backlog (not in considering)
        $epic = Epic::factory()->for($this->team)->for($this->status)->create([
            'title' => 'New Epic',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);
        $epic->squads()->attach($this->squad);

        // Create 2 existing considering items
        $existingEpics = [];
        for ($i = 0; $i < 2; $i++) {
            $existingEpic = Epic::factory()->for($this->team)->for($this->status)->create([
                'title' => "Existing $i",
                'start_date' => '2026-01-01',
                'end_date' => '2026-03-31',
            ]);
            $existingEpic->squads()->attach($this->squad);
            BacklogConsideration::create([
                'epic_id' => $existingEpic->id,
                'squad_id' => $this->squad->id,
                'quarter' => 'Q1-2026',
            ]);
            $existingEpics[] = $existingEpic;
        }

        // Drag new epic to considering at position 1 (middle)
        Livewire::test('planning.show', ['plan' => null])
            ->set('selectedQuarter', 'Q1-2026')
            ->set('selectedSquadIds', [$this->squad->id])
            ->call('sort', $epic->id, 1, 'considering', $this->squad->id);

        // Verify consideration was created
        $this->assertDatabaseHas('backlog_considerations', [
            'epic_id' => $epic->id,
            'squad_id' => $this->squad->id,
            'quarter' => 'Q1-2026',
        ]);

        // Verify the order
        $considerations = BacklogConsideration::where('squad_id', $this->squad->id)
            ->where('quarter', 'Q1-2026')
            ->get();

        expect($considerations)->toHaveCount(3)
            ->and($considerations[0]->epic_id)->toBe($existingEpics[0]->id)
            ->and($considerations[1]->epic_id)->toBe($epic->id)
            ->and($considerations[2]->epic_id)->toBe($existingEpics[1]->id);
    });

    it('considering epics are returned in sort_order', function () {
        // Create epics and add to considering
        $epic1 = Epic::factory()->for($this->team)->for($this->status)->create([
            'title' => 'First Epic',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);
        $epic1->squads()->attach($this->squad);

        $epic2 = Epic::factory()->for($this->team)->for($this->status)->create([
            'title' => 'Second Epic',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);
        $epic2->squads()->attach($this->squad);

        $epic3 = Epic::factory()->for($this->team)->for($this->status)->create([
            'title' => 'Third Epic',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);
        $epic3->squads()->attach($this->squad);

        // Create in reverse order to test sorting
        BacklogConsideration::create([
            'epic_id' => $epic3->id,
            'squad_id' => $this->squad->id,
            'quarter' => 'Q1-2026',
        ]);
        BacklogConsideration::create([
            'epic_id' => $epic1->id,
            'squad_id' => $this->squad->id,
            'quarter' => 'Q1-2026',
        ]);
        BacklogConsideration::create([
            'epic_id' => $epic2->id,
            'squad_id' => $this->squad->id,
            'quarter' => 'Q1-2026',
        ]);

        // Move epic1 to position 0
        $consideration = BacklogConsideration::where('epic_id', $epic1->id)->first();
        $consideration->move(0);

        // Check that component returns considering epics in sorted order
        $component = Livewire::test('planning.show', ['plan' => null])
            ->set('selectedQuarter', 'Q1-2026')
            ->set('selectedSquadIds', [$this->squad->id]);

        $squadData = $component->viewData('squadData');
        $consideringEpics = $squadData[$this->squad->id]['backlog_epics']['considering'];

        // epic1 should be first after moving to position 0
        expect($consideringEpics->first()->id)->toBe($epic1->id);
    });

    it('dragging from plan to considering creates consideration with position', function () {
        $category = \App\Models\Category::factory()->for($this->team)->create(['name' => 'Growth']);

        // Create an epic that's already planned
        $plannedEpic = Epic::factory()->for($this->team)->for($this->status)->create([
            'title' => 'Planned Epic',
        ]);
        $plannedEpic->squads()->attach($this->squad);
        EpicQuarterPlan::create([
            'epic_id' => $plannedEpic->id,
            'squad_id' => $this->squad->id,
            'quarter' => 'Q1-2026',
            'category_id' => $category->id,
            'story_points' => 20,
        ]);

        // Create an existing consideration
        $existingEpic = Epic::factory()->for($this->team)->for($this->status)->create([
            'title' => 'Existing Consideration',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]);
        $existingEpic->squads()->attach($this->squad);
        BacklogConsideration::create([
            'epic_id' => $existingEpic->id,
            'squad_id' => $this->squad->id,
            'quarter' => 'Q1-2026',
        ]);

        // Drag planned epic to considering at position 0
        Livewire::test('planning.show', ['plan' => null])
            ->set('selectedQuarter', 'Q1-2026')
            ->set('selectedSquadIds', [$this->squad->id])
            ->call('sort', $plannedEpic->id, 0, 'considering', $this->squad->id);

        // Should be removed from plan
        $this->assertDatabaseMissing('epic_quarter_plans', [
            'epic_id' => $plannedEpic->id,
            'squad_id' => $this->squad->id,
            'quarter' => 'Q1-2026',
        ]);

        // Should be in considering at position 0
        $considerations = BacklogConsideration::where('squad_id', $this->squad->id)
            ->where('quarter', 'Q1-2026')
            ->get();

        expect($considerations)->toHaveCount(2)
            ->and($considerations[0]->epic_id)->toBe($plannedEpic->id)
            ->and($considerations[1]->epic_id)->toBe($existingEpic->id);
    });

    it('sort order is independent per squad', function () {
        $squad2 = Squad::factory()->for($this->team)->create();

        // Create epics for squad1
        $squad1Epics = [];
        for ($i = 0; $i < 2; $i++) {
            $epic = Epic::factory()->for($this->team)->for($this->status)->create([
                'title' => "Squad1 Epic $i",
                'start_date' => '2026-01-01',
                'end_date' => '2026-03-31',
            ]);
            $epic->squads()->attach($this->squad);
            BacklogConsideration::create([
                'epic_id' => $epic->id,
                'squad_id' => $this->squad->id,
                'quarter' => 'Q1-2026',
            ]);
            $squad1Epics[] = $epic;
        }

        // Create epics for squad2
        $squad2Epics = [];
        for ($i = 0; $i < 2; $i++) {
            $epic = Epic::factory()->for($this->team)->for($this->status)->create([
                'title' => "Squad2 Epic $i",
                'start_date' => '2026-01-01',
                'end_date' => '2026-03-31',
            ]);
            $epic->squads()->attach($squad2);
            BacklogConsideration::create([
                'epic_id' => $epic->id,
                'squad_id' => $squad2->id,
                'quarter' => 'Q1-2026',
            ]);
            $squad2Epics[] = $epic;
        }

        // Reorder squad1 epics
        Livewire::test('planning.show', ['plan' => null])
            ->set('selectedQuarter', 'Q1-2026')
            ->set('selectedSquadIds', [$this->squad->id])
            ->call('sort', $squad1Epics[1]->id, 0, 'considering', $this->squad->id);

        // Squad2 order should be unaffected
        $squad2Considerations = BacklogConsideration::where('squad_id', $squad2->id)
            ->where('quarter', 'Q1-2026')
            ->get();

        expect($squad2Considerations[0]->epic_id)->toBe($squad2Epics[0]->id)
            ->and($squad2Considerations[0]->sort_order)->toBe(0)
            ->and($squad2Considerations[1]->epic_id)->toBe($squad2Epics[1]->id)
            ->and($squad2Considerations[1]->sort_order)->toBe(1);
    });

    it('sort order is independent per quarter', function () {
        // Create epics for Q1-2026
        $q1Epics = [];
        for ($i = 0; $i < 2; $i++) {
            $epic = Epic::factory()->for($this->team)->for($this->status)->create([
                'title' => "Q1 Epic $i",
                'start_date' => '2026-01-01',
                'end_date' => '2026-06-30',
            ]);
            $epic->squads()->attach($this->squad);
            BacklogConsideration::create([
                'epic_id' => $epic->id,
                'squad_id' => $this->squad->id,
                'quarter' => 'Q1-2026',
            ]);
            $q1Epics[] = $epic;
        }

        // Create epics for Q2-2026
        $q2Epics = [];
        for ($i = 0; $i < 2; $i++) {
            $epic = Epic::factory()->for($this->team)->for($this->status)->create([
                'title' => "Q2 Epic $i",
                'start_date' => '2026-04-01',
                'end_date' => '2026-06-30',
            ]);
            $epic->squads()->attach($this->squad);
            BacklogConsideration::create([
                'epic_id' => $epic->id,
                'squad_id' => $this->squad->id,
                'quarter' => 'Q2-2026',
            ]);
            $q2Epics[] = $epic;
        }

        // Reorder Q1 epics
        Livewire::test('planning.show', ['plan' => null])
            ->set('selectedQuarter', 'Q1-2026')
            ->set('selectedSquadIds', [$this->squad->id])
            ->call('sort', $q1Epics[1]->id, 0, 'considering', $this->squad->id);

        // Q2 order should be unaffected
        $q2Considerations = BacklogConsideration::where('squad_id', $this->squad->id)
            ->where('quarter', 'Q2-2026')
            ->get();

        expect($q2Considerations[0]->epic_id)->toBe($q2Epics[0]->id)
            ->and($q2Considerations[0]->sort_order)->toBe(0)
            ->and($q2Considerations[1]->epic_id)->toBe($q2Epics[1]->id)
            ->and($q2Considerations[1]->sort_order)->toBe(1);
    });
});
