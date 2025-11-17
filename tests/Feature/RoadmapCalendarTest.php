<?php

use App\Models\Epic;
use App\Models\Squad;
use App\Models\Status;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(\Database\Seeders\StatusSeeder::class);
});

it('can view roadmap calendar', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)
        ->get('/roadmap')
        ->assertSuccessful();
});

it('displays epics on calendar', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $squad = Squad::factory()->for($user->currentTeam)->create();

    $epic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create([
            'title' => 'Calendar Test Epic',
            'start_date' => now()->addDays(10),
            'end_date' => now()->addDays(30),
        ]);
    $epic->squads()->attach($squad);

    $this->actingAs($user);

    Livewire::test('roadmap.calendar')
        ->assertSee('Calendar Test Epic');
});

it('can switch between timeline and calendar views', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)
        ->get('/roadmap')
        ->assertSuccessful();

    $this->get('/roadmap/timeline')
        ->assertSuccessful();
});
