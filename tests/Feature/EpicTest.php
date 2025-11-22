<?php

use App\Models\Epic;
use App\Models\Squad;
use App\Models\Status;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(\Database\Seeders\StatusSeeder::class);
});

it('can view epics index', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)
        ->get('/epics')
        ->assertSuccessful();
});

it('can create an epic', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $squad = Squad::factory()->for($user->currentTeam)->create();
    $status = Status::where('slug', 'not-started')->first();

    $this->actingAs($user);

    Livewire::test('epics.create')
        ->set('title', 'Payment Gateway Integration')
        ->set('description', 'Integrate new payment gateway')
        ->set('status_id', $status->id)
        ->set('start_date', '2025-01-01')
        ->set('end_date', '2025-03-31')
        ->set('squad_ids', [$squad->id])
        ->call('save')
        ->assertRedirect('/epics');

    $this->assertDatabaseHas('epics', [
        'team_id' => $user->currentTeam->id,
        'title' => 'Payment Gateway Integration',
    ]);
});

it('can update an epic', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $squad = Squad::factory()->for($user->currentTeam)->create();
    $epic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create();
    $epic->squads()->attach($squad);

    $this->actingAs($user);

    Livewire::test('epics.edit', ['epic' => $epic])
        ->set('title', 'Updated Epic Title')
        ->call('save')
        ->assertRedirect('/epics');

    $epic->refresh();
    expect($epic->title)->toBe('Updated Epic Title');
});

it('can delete an epic', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $squad = Squad::factory()->for($user->currentTeam)->create();
    $epic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create();
    $epic->squads()->attach($squad);

    $this->actingAs($user);

    Livewire::test('epics.edit', ['epic' => $epic])
        ->call('delete')
        ->assertRedirect('/epics');

    $this->assertDatabaseMissing('epics', ['id' => $epic->id]);
});

it('cannot view epics from another team', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $otherTeam = Team::factory()->create();
    $status = Status::first();
    $otherEpic = Epic::factory()
        ->for($otherTeam)
        ->for($status)
        ->create();

    $this->actingAs($user)
        ->get("/epics/{$otherEpic->id}/edit")
        ->assertForbidden();
});

it('can create an epic without squads', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::where('slug', 'not-started')->first();

    $this->actingAs($user);

    Livewire::test('epics.create')
        ->set('title', 'Test Epic Without Squads')
        ->set('description', 'This epic has no squads assigned')
        ->set('status_id', $status->id)
        ->set('squad_ids', [])
        ->call('save')
        ->assertRedirect('/epics');

    $this->assertDatabaseHas('epics', [
        'team_id' => $user->currentTeam->id,
        'title' => 'Test Epic Without Squads',
    ]);
});

it('shows warning when title is modified', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $epic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create(['title' => 'Original Title']);

    $this->actingAs($user);

    Livewire::test('epics.edit', ['epic' => $epic])
        ->assertSet('title', 'Original Title')
        ->assertDontSee('You have unsaved changes')
        ->set('title', 'Modified Title')
        ->assertSee('You have unsaved changes');
});

it('shows warning when description is modified', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $epic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create(['description' => 'Original description']);

    $this->actingAs($user);

    Livewire::test('epics.edit', ['epic' => $epic])
        ->assertDontSee('You have unsaved changes')
        ->set('description', 'Modified description')
        ->assertSee('You have unsaved changes');
});

it('shows warning when status is modified', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status1 = Status::first();
    $status2 = Status::skip(1)->first();
    $epic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status1)
        ->create();

    $this->actingAs($user);

    Livewire::test('epics.edit', ['epic' => $epic])
        ->assertDontSee('You have unsaved changes')
        ->set('status_id', (string) $status2->id)
        ->assertSee('You have unsaved changes');
});

it('shows warning when priority is modified', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $epic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create(['priority' => 'low']);

    $this->actingAs($user);

    Livewire::test('epics.edit', ['epic' => $epic])
        ->assertDontSee('You have unsaved changes')
        ->set('priority', 'high')
        ->assertSee('You have unsaved changes');
});

it('shows warning when dates are modified', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $epic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create([
            'start_date' => '2025-01-01',
            'end_date' => '2025-03-31',
        ]);

    $this->actingAs($user);

    Livewire::test('epics.edit', ['epic' => $epic])
        ->assertDontSee('You have unsaved changes')
        ->set('start_date', '2025-02-01')
        ->assertSee('You have unsaved changes');
});

it('shows warning when squads are modified', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $squad1 = Squad::factory()->for($user->currentTeam)->create();
    $squad2 = Squad::factory()->for($user->currentTeam)->create();
    $epic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create();
    $epic->squads()->attach($squad1);

    $this->actingAs($user);

    Livewire::test('epics.edit', ['epic' => $epic])
        ->assertDontSee('You have unsaved changes')
        ->set('squad_ids', [(string) $squad1->id, (string) $squad2->id])
        ->assertSee('You have unsaved changes');
});

it('shows warning when squad data is modified', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $squad = Squad::factory()->for($user->currentTeam)->create();
    $epic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create();
    $epic->squads()->attach($squad, [
        'start_date' => '2025-01-01',
        'end_date' => '2025-03-31',
        'story_points' => 10,
    ]);

    $this->actingAs($user);

    Livewire::test('epics.edit', ['epic' => $epic])
        ->assertDontSee('You have unsaved changes')
        ->set('squad_data.'.$squad->id.'.story_points', '20')
        ->assertSee('You have unsaved changes');
});

it('can discard unsaved changes', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $epic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create(['title' => 'Original Title']);

    $this->actingAs($user);

    Livewire::test('epics.edit', ['epic' => $epic])
        ->set('title', 'Modified Title')
        ->assertSee('You have unsaved changes')
        ->call('discardChanges')
        ->assertSet('title', 'Original Title')
        ->assertDontSee('You have unsaved changes');
});

it('does not show warning when no modifications are made', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $epic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create();

    $this->actingAs($user);

    Livewire::test('epics.edit', ['epic' => $epic])
        ->assertDontSee('You have unsaved changes');
});
