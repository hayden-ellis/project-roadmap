<?php

use App\Models\Epic;
use App\Models\Squad;
use App\Models\Status;
use App\Models\Story;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(\Database\Seeders\StatusSeeder::class);
});

it('can create a story', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::where('slug', 'not-started')->first();
    $squad = Squad::factory()->for($user->currentTeam)->create();
    $epic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create();
    $epic->squads()->attach($squad);

    $this->actingAs($user);

    Livewire::test('stories.create', ['epic' => $epic])
        ->set('title', 'Design API endpoints')
        ->set('description', 'Create REST API design')
        ->set('squad_id', $squad->id)
        ->set('status_id', $status->id)
        ->set('start_date', '2025-01-01')
        ->set('end_date', '2025-01-15')
        ->call('save')
        ->assertRedirect("/epics/{$epic->id}/edit");

    $this->assertDatabaseHas('stories', [
        'epic_id' => $epic->id,
        'squad_id' => $squad->id,
        'title' => 'Design API endpoints',
    ]);
});

it('can update a story', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $squad = Squad::factory()->for($user->currentTeam)->create();
    $epic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create();
    $epic->squads()->attach($squad);

    $story = Story::factory()
        ->for($epic)
        ->for($squad)
        ->for($status)
        ->create();

    $this->actingAs($user);

    Livewire::test('stories.edit', ['story' => $story])
        ->set('title', 'Updated Story Title')
        ->call('save')
        ->assertRedirect("/epics/{$epic->id}/edit");

    $story->refresh();
    expect($story->title)->toBe('Updated Story Title');
});

it('can delete a story', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $squad = Squad::factory()->for($user->currentTeam)->create();
    $epic = Epic::factory()
        ->for($user->currentTeam)
        ->for($status)
        ->create();
    $epic->squads()->attach($squad);

    $story = Story::factory()
        ->for($epic)
        ->for($squad)
        ->for($status)
        ->create();

    $this->actingAs($user);

    Livewire::test('stories.edit', ['story' => $story])
        ->call('delete')
        ->assertRedirect("/epics/{$epic->id}/edit");

    $this->assertDatabaseMissing('stories', ['id' => $story->id]);
});

it('cannot update stories from another team', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $otherUser = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $squad = Squad::factory()->for($otherUser->currentTeam)->create();
    $epic = Epic::factory()
        ->for($otherUser->currentTeam)
        ->for($status)
        ->create();
    $epic->squads()->attach($squad);

    $story = Story::factory()
        ->for($epic)
        ->for($squad)
        ->for($status)
        ->create();

    $this->actingAs($user)
        ->get("/stories/{$story->id}/edit")
        ->assertForbidden();

    $story->refresh();
    expect($story->title)->not->toBe('Hacked Story');
});
