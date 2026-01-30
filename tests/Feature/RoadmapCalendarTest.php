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

it('can update squad work inline from calendar', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $squad = Squad::factory()->for($user->currentTeam)->create();

    $epic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create([
            'start_date' => now()->addDays(10),
            'end_date' => now()->addDays(30),
        ]);

    $epic->squads()->attach($squad, [
        'start_date' => now()->addDays(10),
        'end_date' => now()->addDays(30),
    ]);

    $this->actingAs($user);

    Livewire::test('roadmap.calendar')
        ->call('updateSquadWork', $epic->id, $squad->id, now()->addDays(15)->format('Y-m-d'), now()->addDays(35)->format('Y-m-d'));

    $pivot = $epic->squads()->first()->pivot;
    expect($pivot->start_date->format('Y-m-d'))->toEqual(now()->addDays(15)->format('Y-m-d'))
        ->and($pivot->end_date->format('Y-m-d'))->toEqual(now()->addDays(35)->format('Y-m-d'));
});

it('can clear squad work values with null', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $squad = Squad::factory()->for($user->currentTeam)->create();

    $epic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create([
            'start_date' => now()->addDays(10),
            'end_date' => now()->addDays(30),
        ]);

    $epic->squads()->attach($squad, [
        'start_date' => now()->addDays(10),
        'end_date' => now()->addDays(30),
    ]);

    $this->actingAs($user);

    Livewire::test('roadmap.calendar')
        ->call('updateSquadWork', $epic->id, $squad->id, null, null);

    expect($epic->squads()->first()->pivot)
        ->start_date->toBeNull()
        ->end_date->toBeNull();
});

it('requires authorization to update squad work', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $otherUser = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $squad = Squad::factory()->for($otherUser->currentTeam)->create();

    $epic = Epic::factory()
        ->for($otherUser->currentTeam)
        ->for($status)
        ->create([
            'start_date' => now()->addDays(10),
            'end_date' => now()->addDays(30),
        ]);

    $epic->squads()->attach($squad);

    $this->actingAs($user);

    Livewire::test('roadmap.calendar')
        ->call('updateSquadWork', $epic->id, $squad->id, now()->addDays(15)->format('Y-m-d'), now()->addDays(35)->format('Y-m-d'))
        ->assertForbidden();
});
