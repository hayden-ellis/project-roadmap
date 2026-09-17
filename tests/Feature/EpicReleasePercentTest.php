<?php

use App\Models\Epic;
use App\Models\Status;
use App\Models\User;
use Livewire\Livewire;

/**
 * Release % is the team's own read on how much of an epic has reached
 * users: typed in on the epic page or the board flyout, shown on the list
 * and the cards. Null means nobody has said, and reads as nothing at all.
 */
beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;

    $this->status = Status::create([
        'team_id' => $this->team->id, 'name' => 'Building', 'color' => '#22C55E', 'is_default' => true,
    ]);

    $this->epic = Epic::create([
        'team_id' => $this->team->id,
        'title' => 'Smart Charging Scheduler',
        'status_id' => $this->status->id,
        'priority' => 'medium',
    ]);

    $this->actingAs($this->user);
});

// ---------------------------------------------------------------- epic page

it('saves the release percentage from the epic page as soon as it changes', function () {
    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->set('release_percent', 40)
        ->assertHasNoErrors()
        ->assertDispatched('epic-saved');

    expect($this->epic->fresh()->release_percent)->toBe(40);
});

it('clears the release percentage when the field is emptied', function () {
    $this->epic->update(['release_percent' => 40]);

    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->assertSet('release_percent', 40)
        ->set('release_percent', null)
        ->assertHasNoErrors();

    expect($this->epic->fresh()->release_percent)->toBeNull();
});

it('rejects a release percentage outside 0 to 100', function () {
    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->set('release_percent', 120)
        ->assertHasErrors(['release_percent'])
        ->set('release_percent', -5)
        ->assertHasErrors(['release_percent']);

    expect($this->epic->fresh()->release_percent)->toBeNull();
});

// ------------------------------------------------------------------- board

it('saves the release percentage from the board flyout', function () {
    Livewire::test('now')
        ->call('open', $this->epic->id)
        ->set('editReleasePercent', 75)
        ->assertHasNoErrors();

    expect($this->epic->fresh()->release_percent)->toBe(75);
});

it('shows the release percentage on the card and nothing when unset', function () {
    Livewire::test('now')->assertDontSee('% released');

    $this->epic->update(['release_percent' => 75]);

    Livewire::test('now')
        ->assertSee('75% released')
        ->assertSee('75%');
});

// -------------------------------------------------------------------- list

it('shows a release column on the epics list', function () {
    $this->epic->update(['release_percent' => 100]);

    Livewire::test('epics.index')
        ->assertSee('Release')
        ->assertSee('100% released');
});

it('sorts the list by release percentage with the unset ones last', function () {
    $this->epic->update(['release_percent' => 20]);
    $shipped = Epic::create(['team_id' => $this->team->id, 'title' => 'Fleet Billing', 'status_id' => $this->status->id, 'release_percent' => 90]);
    $unsaid = Epic::create(['team_id' => $this->team->id, 'title' => 'Roaming Tariffs', 'status_id' => $this->status->id]);

    Livewire::test('epics.index')
        ->call('setSortBy', 'release_percent')
        ->assertSet('sortDirection', 'desc')
        ->assertSeeInOrder(['Fleet Billing', 'Smart Charging Scheduler', 'Roaming Tariffs'])
        ->call('setSortBy', 'release_percent')
        ->assertSet('sortDirection', 'asc')
        ->assertSeeInOrder(['Smart Charging Scheduler', 'Fleet Billing', 'Roaming Tariffs']);
});
