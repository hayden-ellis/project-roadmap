<?php

use App\Models\Epic;
use App\Models\EpicSquad;
use App\Models\Squad;
use App\Models\Status;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\StatusSeeder::class);

    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->squad = Squad::factory()->for($this->team)->create();
    $this->status = Status::first();
});

it('auto assigns sort_order on creation', function () {
    $epic1 = Epic::factory()->for($this->team)->for($this->status)->create();
    $epic1->squads()->attach($this->squad, ['planned_quarter' => 'Q1-2026']);

    $epic2 = Epic::factory()->for($this->team)->for($this->status)->create();
    $epic2->squads()->attach($this->squad, ['planned_quarter' => 'Q1-2026']);

    $epic3 = Epic::factory()->for($this->team)->for($this->status)->create();
    $epic3->squads()->attach($this->squad, ['planned_quarter' => 'Q1-2026']);

    // Fetch the pivot records
    $pivots = EpicSquad::where('squad_id', $this->squad->id)
        ->where('planned_quarter', 'Q1-2026')
        ->orderBy('sort_order')
        ->get();

    expect($pivots)->toHaveCount(3)
        ->and($pivots[0]->sort_order)->toBe(0)
        ->and($pivots[1]->sort_order)->toBe(1)
        ->and($pivots[2]->sort_order)->toBe(2);
});

it('scopes sort_order by squad and quarter', function () {
    $squad2 = Squad::factory()->for($this->team)->create();

    // Epics for squad1, Q1-2026
    $epic1 = Epic::factory()->for($this->team)->for($this->status)->create();
    $epic1->squads()->attach($this->squad, ['planned_quarter' => 'Q1-2026']);

    // Epics for squad2, Q1-2026 (should have independent ordering)
    $epic2 = Epic::factory()->for($this->team)->for($this->status)->create();
    $epic2->squads()->attach($squad2, ['planned_quarter' => 'Q1-2026']);

    // Epics for squad1, Q2-2026 (should have independent ordering)
    $epic3 = Epic::factory()->for($this->team)->for($this->status)->create();
    $epic3->squads()->attach($this->squad, ['planned_quarter' => 'Q2-2026']);

    $pivot1 = EpicSquad::where('epic_id', $epic1->id)->where('squad_id', $this->squad->id)->first();
    $pivot2 = EpicSquad::where('epic_id', $epic2->id)->where('squad_id', $squad2->id)->first();
    $pivot3 = EpicSquad::where('epic_id', $epic3->id)->where('squad_id', $this->squad->id)->first();

    // Each should start at 0 because they're in different scopes
    expect($pivot1->sort_order)->toBe(0)
        ->and($pivot2->sort_order)->toBe(0)
        ->and($pivot3->sort_order)->toBe(0);
});

it('can move item to new position', function () {
    // Create 4 epics
    $epics = [];
    for ($i = 0; $i < 4; $i++) {
        $epic = Epic::factory()->for($this->team)->for($this->status)->create(['title' => "Epic $i"]);
        $epic->squads()->attach($this->squad, ['planned_quarter' => 'Q1-2026']);
        $epics[] = $epic;
    }

    // Initially: 0, 1, 2, 3
    // Move epic3 (position 3) to position 1
    $pivot3 = EpicSquad::where('epic_id', $epics[3]->id)->where('squad_id', $this->squad->id)->first();
    $pivot3->move(1);

    // Fetch updated positions
    $updatedPivots = [];
    foreach ($epics as $epic) {
        $updatedPivots[$epic->id] = EpicSquad::where('epic_id', $epic->id)
            ->where('squad_id', $this->squad->id)
            ->first()
            ->sort_order;
    }

    // After move: 0 stays at 0, 3 moves to 1, 1 shifts to 2, 2 shifts to 3
    expect($updatedPivots[$epics[0]->id])->toBe(0)
        ->and($updatedPivots[$epics[3]->id])->toBe(1)
        ->and($updatedPivots[$epics[1]->id])->toBe(2)
        ->and($updatedPivots[$epics[2]->id])->toBe(3);
});

it('can move item forward', function () {
    // Create 4 epics
    $epics = [];
    for ($i = 0; $i < 4; $i++) {
        $epic = Epic::factory()->for($this->team)->for($this->status)->create(['title' => "Epic $i"]);
        $epic->squads()->attach($this->squad, ['planned_quarter' => 'Q1-2026']);
        $epics[] = $epic;
    }

    // Initially: 0, 1, 2, 3
    // Move epic0 (position 0) to position 2
    $pivot0 = EpicSquad::where('epic_id', $epics[0]->id)->where('squad_id', $this->squad->id)->first();
    $pivot0->move(2);

    // Fetch updated positions
    $updatedPivots = [];
    foreach ($epics as $epic) {
        $updatedPivots[$epic->id] = EpicSquad::where('epic_id', $epic->id)
            ->where('squad_id', $this->squad->id)
            ->first()
            ->sort_order;
    }

    // After move: 1 shifts to 0, 2 shifts to 1, 0 moves to 2, 3 stays at 3
    expect($updatedPivots[$epics[1]->id])->toBe(0)
        ->and($updatedPivots[$epics[2]->id])->toBe(1)
        ->and($updatedPivots[$epics[0]->id])->toBe(2)
        ->and($updatedPivots[$epics[3]->id])->toBe(3);
});

it('move to same position is a no-op', function () {
    $epic = Epic::factory()->for($this->team)->for($this->status)->create();
    $epic->squads()->attach($this->squad, ['planned_quarter' => 'Q1-2026']);

    $pivot = EpicSquad::where('epic_id', $epic->id)->where('squad_id', $this->squad->id)->first();
    $originalSortOrder = $pivot->sort_order;

    $pivot->move($originalSortOrder);

    $pivot->refresh();
    expect($pivot->sort_order)->toBe($originalSortOrder);
});

it('displace moves item to high sort order', function () {
    $epics = [];
    for ($i = 0; $i < 3; $i++) {
        $epic = Epic::factory()->for($this->team)->for($this->status)->create();
        $epic->squads()->attach($this->squad, ['planned_quarter' => 'Q1-2026']);
        $epics[] = $epic;
    }

    // Displace the middle item
    $pivot1 = EpicSquad::where('epic_id', $epics[1]->id)->where('squad_id', $this->squad->id)->first();
    $pivot1->displace();

    $pivot1->refresh();

    // Should have a very high sort_order
    expect($pivot1->sort_order)->toBe(99999);
});

it('orders by sort_order by default via global scope', function () {
    // Create epics in random order
    $epic3 = Epic::factory()->for($this->team)->for($this->status)->create(['title' => 'Epic C']);
    $epic3->squads()->attach($this->squad, ['planned_quarter' => 'Q1-2026']);

    $epic1 = Epic::factory()->for($this->team)->for($this->status)->create(['title' => 'Epic A']);
    $epic1->squads()->attach($this->squad, ['planned_quarter' => 'Q1-2026']);

    $epic2 = Epic::factory()->for($this->team)->for($this->status)->create(['title' => 'Epic B']);
    $epic2->squads()->attach($this->squad, ['planned_quarter' => 'Q1-2026']);

    // Move epic1 to position 0
    $pivot1 = EpicSquad::where('epic_id', $epic1->id)->where('squad_id', $this->squad->id)->first();
    $pivot1->move(0);

    // Query should return in sort_order
    $pivots = EpicSquad::where('squad_id', $this->squad->id)
        ->where('planned_quarter', 'Q1-2026')
        ->get();

    // epic1 should be first (sort_order 0)
    expect($pivots->first()->epic_id)->toBe($epic1->id);
});
