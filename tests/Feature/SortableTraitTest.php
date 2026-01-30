<?php

use App\Models\Epic;
use App\Models\EpicQuarterPlan;
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
    EpicQuarterPlan::create(['epic_id' => $epic1->id, 'squad_id' => $this->squad->id, 'quarter' => 'Q1-2026']);

    $epic2 = Epic::factory()->for($this->team)->for($this->status)->create();
    EpicQuarterPlan::create(['epic_id' => $epic2->id, 'squad_id' => $this->squad->id, 'quarter' => 'Q1-2026']);

    $epic3 = Epic::factory()->for($this->team)->for($this->status)->create();
    EpicQuarterPlan::create(['epic_id' => $epic3->id, 'squad_id' => $this->squad->id, 'quarter' => 'Q1-2026']);

    // Fetch the records
    $plans = EpicQuarterPlan::where('squad_id', $this->squad->id)
        ->where('quarter', 'Q1-2026')
        ->orderBy('sort_order')
        ->get();

    expect($plans)->toHaveCount(3)
        ->and($plans[0]->sort_order)->toBe(0)
        ->and($plans[1]->sort_order)->toBe(1)
        ->and($plans[2]->sort_order)->toBe(2);
});

it('scopes sort_order by squad and quarter', function () {
    $squad2 = Squad::factory()->for($this->team)->create();

    // Epics for squad1, Q1-2026
    $epic1 = Epic::factory()->for($this->team)->for($this->status)->create();
    EpicQuarterPlan::create(['epic_id' => $epic1->id, 'squad_id' => $this->squad->id, 'quarter' => 'Q1-2026']);

    // Epics for squad2, Q1-2026 (should have independent ordering)
    $epic2 = Epic::factory()->for($this->team)->for($this->status)->create();
    EpicQuarterPlan::create(['epic_id' => $epic2->id, 'squad_id' => $squad2->id, 'quarter' => 'Q1-2026']);

    // Epics for squad1, Q2-2026 (should have independent ordering)
    $epic3 = Epic::factory()->for($this->team)->for($this->status)->create();
    EpicQuarterPlan::create(['epic_id' => $epic3->id, 'squad_id' => $this->squad->id, 'quarter' => 'Q2-2026']);

    $plan1 = EpicQuarterPlan::where('epic_id', $epic1->id)->where('squad_id', $this->squad->id)->first();
    $plan2 = EpicQuarterPlan::where('epic_id', $epic2->id)->where('squad_id', $squad2->id)->first();
    $plan3 = EpicQuarterPlan::where('epic_id', $epic3->id)->where('squad_id', $this->squad->id)->first();

    // Each should start at 0 because they're in different scopes
    expect($plan1->sort_order)->toBe(0)
        ->and($plan2->sort_order)->toBe(0)
        ->and($plan3->sort_order)->toBe(0);
});

it('can move item to new position', function () {
    // Create 4 epics
    $epics = [];
    for ($i = 0; $i < 4; $i++) {
        $epic = Epic::factory()->for($this->team)->for($this->status)->create(['title' => "Epic $i"]);
        EpicQuarterPlan::create(['epic_id' => $epic->id, 'squad_id' => $this->squad->id, 'quarter' => 'Q1-2026']);
        $epics[] = $epic;
    }

    // Initially: 0, 1, 2, 3
    // Move epic3 (position 3) to position 1
    $plan3 = EpicQuarterPlan::where('epic_id', $epics[3]->id)->where('squad_id', $this->squad->id)->first();
    $plan3->move(1);

    // Fetch updated positions
    $updatedPlans = [];
    foreach ($epics as $epic) {
        $updatedPlans[$epic->id] = EpicQuarterPlan::where('epic_id', $epic->id)
            ->where('squad_id', $this->squad->id)
            ->first()
            ->sort_order;
    }

    // After move: 0 stays at 0, 3 moves to 1, 1 shifts to 2, 2 shifts to 3
    expect($updatedPlans[$epics[0]->id])->toBe(0)
        ->and($updatedPlans[$epics[3]->id])->toBe(1)
        ->and($updatedPlans[$epics[1]->id])->toBe(2)
        ->and($updatedPlans[$epics[2]->id])->toBe(3);
});

it('can move item forward', function () {
    // Create 4 epics
    $epics = [];
    for ($i = 0; $i < 4; $i++) {
        $epic = Epic::factory()->for($this->team)->for($this->status)->create(['title' => "Epic $i"]);
        EpicQuarterPlan::create(['epic_id' => $epic->id, 'squad_id' => $this->squad->id, 'quarter' => 'Q1-2026']);
        $epics[] = $epic;
    }

    // Initially: 0, 1, 2, 3
    // Move epic0 (position 0) to position 2
    $plan0 = EpicQuarterPlan::where('epic_id', $epics[0]->id)->where('squad_id', $this->squad->id)->first();
    $plan0->move(2);

    // Fetch updated positions
    $updatedPlans = [];
    foreach ($epics as $epic) {
        $updatedPlans[$epic->id] = EpicQuarterPlan::where('epic_id', $epic->id)
            ->where('squad_id', $this->squad->id)
            ->first()
            ->sort_order;
    }

    // After move: 1 shifts to 0, 2 shifts to 1, 0 moves to 2, 3 stays at 3
    expect($updatedPlans[$epics[1]->id])->toBe(0)
        ->and($updatedPlans[$epics[2]->id])->toBe(1)
        ->and($updatedPlans[$epics[0]->id])->toBe(2)
        ->and($updatedPlans[$epics[3]->id])->toBe(3);
});

it('move to same position is a no-op', function () {
    $epic = Epic::factory()->for($this->team)->for($this->status)->create();
    EpicQuarterPlan::create(['epic_id' => $epic->id, 'squad_id' => $this->squad->id, 'quarter' => 'Q1-2026']);

    $plan = EpicQuarterPlan::where('epic_id', $epic->id)->where('squad_id', $this->squad->id)->first();
    $originalSortOrder = $plan->sort_order;

    $plan->move($originalSortOrder);

    $plan->refresh();
    expect($plan->sort_order)->toBe($originalSortOrder);
});

it('displace moves item to high sort order', function () {
    $epics = [];
    for ($i = 0; $i < 3; $i++) {
        $epic = Epic::factory()->for($this->team)->for($this->status)->create();
        EpicQuarterPlan::create(['epic_id' => $epic->id, 'squad_id' => $this->squad->id, 'quarter' => 'Q1-2026']);
        $epics[] = $epic;
    }

    // Displace the middle item
    $plan1 = EpicQuarterPlan::where('epic_id', $epics[1]->id)->where('squad_id', $this->squad->id)->first();
    $plan1->displace();

    $plan1->refresh();

    // Should have a very high sort_order
    expect($plan1->sort_order)->toBe(99999);
});

it('orders by sort_order by default via global scope', function () {
    // Create epics in random order
    $epic3 = Epic::factory()->for($this->team)->for($this->status)->create(['title' => 'Epic C']);
    EpicQuarterPlan::create(['epic_id' => $epic3->id, 'squad_id' => $this->squad->id, 'quarter' => 'Q1-2026']);

    $epic1 = Epic::factory()->for($this->team)->for($this->status)->create(['title' => 'Epic A']);
    EpicQuarterPlan::create(['epic_id' => $epic1->id, 'squad_id' => $this->squad->id, 'quarter' => 'Q1-2026']);

    $epic2 = Epic::factory()->for($this->team)->for($this->status)->create(['title' => 'Epic B']);
    EpicQuarterPlan::create(['epic_id' => $epic2->id, 'squad_id' => $this->squad->id, 'quarter' => 'Q1-2026']);

    // Move epic1 to position 0
    $plan1 = EpicQuarterPlan::where('epic_id', $epic1->id)->where('squad_id', $this->squad->id)->first();
    $plan1->move(0);

    // Query should return in sort_order
    $plans = EpicQuarterPlan::where('squad_id', $this->squad->id)
        ->where('quarter', 'Q1-2026')
        ->get();

    // epic1 should be first (sort_order 0)
    expect($plans->first()->epic_id)->toBe($epic1->id);
});
