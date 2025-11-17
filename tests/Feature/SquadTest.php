<?php

use App\Models\Squad;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

it('can view squads index', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)
        ->get('/squads')
        ->assertSuccessful();
});

it('can create a squad', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user);

    Livewire::test('squads.create')
        ->set('name', 'Charging')
        ->set('description', 'Manages EV charging infrastructure')
        ->set('color', '#EF4444')
        ->call('save')
        ->assertRedirect('/squads');

    $this->assertDatabaseHas('squads', [
        'team_id' => $user->currentTeam->id,
        'name' => 'Charging',
        'description' => 'Manages EV charging infrastructure',
        'color' => '#EF4444',
    ]);
});

it('can update a squad', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $squad = Squad::factory()->for($user->currentTeam)->create();

    $this->actingAs($user);

    Livewire::test('squads.edit', ['squad' => $squad])
        ->set('name', 'Updated Squad')
        ->set('description', 'Updated description')
        ->call('save')
        ->assertRedirect('/squads');

    $squad->refresh();
    expect($squad->name)->toBe('Updated Squad');
    expect($squad->description)->toBe('Updated description');
});

it('can delete a squad', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $squad = Squad::factory()->for($user->currentTeam)->create();

    $this->actingAs($user);

    Livewire::test('squads.edit', ['squad' => $squad])
        ->call('delete')
        ->assertRedirect('/squads');

    $this->assertDatabaseMissing('squads', ['id' => $squad->id]);
});

it('cannot view squads from another team', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $otherTeam = Team::factory()->create();
    $otherSquad = Squad::factory()->for($otherTeam)->create();

    $this->actingAs($user)
        ->get("/squads/{$otherSquad->id}/edit")
        ->assertForbidden();
});

it('cannot update squads from another team', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $otherTeam = Team::factory()->create();
    $otherSquad = Squad::factory()->for($otherTeam)->create();

    $this->actingAs($user)
        ->get("/squads/{$otherSquad->id}/edit")
        ->assertForbidden();

    $otherSquad->refresh();
    expect($otherSquad->name)->not->toBe('Hacked Squad');
});
