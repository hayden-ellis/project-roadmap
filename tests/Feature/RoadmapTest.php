<?php

use App\Models\Epic;
use App\Models\Squad;
use App\Models\Status;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(\Database\Seeders\StatusSeeder::class);
});

it('can view roadmap', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)
        ->get('/roadmap')
        ->assertSuccessful();
});

it('displays epics in date range', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $squad = Squad::factory()->for($user->currentTeam)->create();

    $epicInRange = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create([
            'title' => 'Epic In Range',
            'start_date' => now()->addDays(10),
            'end_date' => now()->addDays(30),
        ]);
    $epicInRange->squads()->attach($squad);

    $epicOutRange = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create([
            'title' => 'Epic Out Range',
            'start_date' => now()->subDays(100),
            'end_date' => now()->subDays(90),
        ]);
    $epicOutRange->squads()->attach($squad);

    $this->actingAs($user);

    Livewire::test('roadmap.timeline')
        ->assertSee('Epic In Range')
        ->assertDontSee('Epic Out Range');
});

it('filters epics by selected squads', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $squad1 = Squad::factory()->for($user->currentTeam)->create(['name' => 'Charging']);
    $squad2 = Squad::factory()->for($user->currentTeam)->create(['name' => 'Pricing']);

    $epic1 = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create([
            'title' => 'Charging Epic',
            'start_date' => now()->addDays(10),
            'end_date' => now()->addDays(30),
        ]);
    $epic1->squads()->attach($squad1);

    $epic2 = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create([
            'title' => 'Pricing Epic',
            'start_date' => now()->addDays(10),
            'end_date' => now()->addDays(30),
        ]);
    $epic2->squads()->attach($squad2);

    $this->actingAs($user);

    Livewire::test('roadmap.timeline')
        ->set('selected_squads', [$squad1->id])
        ->assertSee('Charging Epic')
        ->assertDontSee('Pricing Epic');
});

it('only shows epics from current team', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $otherUser = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $squad = Squad::factory()->for($user->currentTeam)->create();
    $otherSquad = Squad::factory()->for($otherUser->currentTeam)->create();

    $myEpic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create([
            'title' => 'My Epic',
            'start_date' => now()->addDays(10),
            'end_date' => now()->addDays(30),
        ]);
    $myEpic->squads()->attach($squad);

    $otherEpic = Epic::factory()
        ->for($otherUser->currentTeam)
        ->for($status)
        ->create([
            'title' => 'Other Epic',
            'start_date' => now()->addDays(10),
            'end_date' => now()->addDays(30),
        ]);
    $otherEpic->squads()->attach($otherSquad);

    $this->actingAs($user);

    Livewire::test('roadmap.timeline')
        ->assertSee('My Epic')
        ->assertDontSee('Other Epic');
});
