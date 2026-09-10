<?php

use App\Models\Status;
use App\Models\User;
use Livewire\Livewire;

/**
 * The Add epic modal the matrix and the epics list share: a title and a
 * priority, and the epic lands where those imply.
 */
beforeEach(function () {
    $user = User::factory()->withPersonalTeam()->create();
    $this->team = $user->currentTeam;

    $this->actingAs($user);
});

it('adds an epic with a title and priority, then resets', function () {
    $status = Status::create(['team_id' => $this->team->id, 'name' => 'Backlog', 'color' => '#71717a', 'position' => 0, 'is_default' => true]);

    Livewire::test('quick-add-epic')
        ->set('title', 'Payments List View')
        ->set('priority', 'high')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('title', '')
        ->assertSet('priority', 'medium')
        ->assertDispatched('epic-added');

    $epic = $this->team->epics()->where('title', 'Payments List View')->first();

    expect($epic)->not->toBeNull()
        ->and($epic->status_id)->toBe($status->id)
        ->and($epic->importance)->toBe('high')
        ->and($epic->urgency)->toBe('not_urgent');
});

it('asks for a name', function () {
    Livewire::test('quick-add-epic')
        ->set('title', '')
        ->call('save')
        ->assertHasErrors(['title' => 'required'])
        ->assertNotDispatched('epic-added');

    expect($this->team->epics()->count())->toBe(0);
});

it('renders its modal on the matrix and the epics list', function () {
    $this->get('/matrix')->assertSeeLivewire('quick-add-epic');
    $this->get('/epics')->assertSeeLivewire('quick-add-epic');
});
