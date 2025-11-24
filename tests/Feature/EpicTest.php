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

// Filtering and Sorting Tests

it('can filter epics by a single squad', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $squad1 = Squad::factory()->for($user->currentTeam)->create(['name' => 'Squad Alpha']);
    $squad2 = Squad::factory()->for($user->currentTeam)->create(['name' => 'Squad Beta']);

    $epic1 = Epic::factory()->for($user->currentTeam)->for($status)->create(['title' => 'Epic 1']);
    $epic1->squads()->attach($squad1);

    $epic2 = Epic::factory()->for($user->currentTeam)->for($status)->create(['title' => 'Epic 2']);
    $epic2->squads()->attach($squad2);

    $this->actingAs($user);

    Livewire::test('epics.index')
        ->assertSee('Epic 1')
        ->assertSee('Epic 2')
        ->set('selectedSquadIds', [$squad1->id])
        ->assertSee('Epic 1')
        ->assertDontSee('Epic 2');
});

it('can filter epics by multiple squads', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $squad1 = Squad::factory()->for($user->currentTeam)->create(['name' => 'Squad Alpha']);
    $squad2 = Squad::factory()->for($user->currentTeam)->create(['name' => 'Squad Beta']);
    $squad3 = Squad::factory()->for($user->currentTeam)->create(['name' => 'Squad Gamma']);

    $epic1 = Epic::factory()->for($user->currentTeam)->for($status)->create(['title' => 'Epic 1']);
    $epic1->squads()->attach($squad1);

    $epic2 = Epic::factory()->for($user->currentTeam)->for($status)->create(['title' => 'Epic 2']);
    $epic2->squads()->attach($squad2);

    $epic3 = Epic::factory()->for($user->currentTeam)->for($status)->create(['title' => 'Epic 3']);
    $epic3->squads()->attach($squad3);

    $this->actingAs($user);

    Livewire::test('epics.index')
        ->set('selectedSquadIds', [$squad1->id, $squad2->id])
        ->assertSee('Epic 1')
        ->assertSee('Epic 2')
        ->assertDontSee('Epic 3');
});

it('can filter epics by a single status', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status1 = Status::where('slug', 'not-started')->first();
    $status2 = Status::where('slug', 'in-progress')->first();

    $epic1 = Epic::factory()->for($user->currentTeam)->for($status1)->create(['title' => 'Epic Not Started']);
    $epic2 = Epic::factory()->for($user->currentTeam)->for($status2)->create(['title' => 'Epic In Progress']);

    $this->actingAs($user);

    Livewire::test('epics.index')
        ->assertSee('Epic Not Started')
        ->assertSee('Epic In Progress')
        ->set('selectedStatusIds', [$status1->id])
        ->assertSee('Epic Not Started')
        ->assertDontSee('Epic In Progress');
});

it('can filter epics by multiple statuses', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status1 = Status::where('slug', 'not-started')->first();
    $status2 = Status::where('slug', 'in-progress')->first();
    $status3 = Status::where('slug', 'completed')->first();

    $epic1 = Epic::factory()->for($user->currentTeam)->for($status1)->create(['title' => 'Epic Not Started']);
    $epic2 = Epic::factory()->for($user->currentTeam)->for($status2)->create(['title' => 'Epic In Progress']);
    $epic3 = Epic::factory()->for($user->currentTeam)->for($status3)->create(['title' => 'Epic Completed']);

    $this->actingAs($user);

    Livewire::test('epics.index')
        ->set('selectedStatusIds', [$status1->id, $status2->id])
        ->assertSee('Epic Not Started')
        ->assertSee('Epic In Progress')
        ->assertDontSee('Epic Completed');
});

it('can filter epics by both squads and statuses', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status1 = Status::where('slug', 'not-started')->first();
    $status2 = Status::where('slug', 'in-progress')->first();
    $squad1 = Squad::factory()->for($user->currentTeam)->create(['name' => 'Squad Alpha']);
    $squad2 = Squad::factory()->for($user->currentTeam)->create(['name' => 'Squad Beta']);

    $epic1 = Epic::factory()->for($user->currentTeam)->for($status1)->create(['title' => 'Epic 1']);
    $epic1->squads()->attach($squad1);

    $epic2 = Epic::factory()->for($user->currentTeam)->for($status2)->create(['title' => 'Epic 2']);
    $epic2->squads()->attach($squad1);

    $epic3 = Epic::factory()->for($user->currentTeam)->for($status1)->create(['title' => 'Epic 3']);
    $epic3->squads()->attach($squad2);

    $this->actingAs($user);

    Livewire::test('epics.index')
        ->set('selectedSquadIds', [$squad1->id])
        ->set('selectedStatusIds', [$status1->id])
        ->assertSee('Epic 1')
        ->assertDontSee('Epic 2')
        ->assertDontSee('Epic 3');
});

it('can clear all filters', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $squad = Squad::factory()->for($user->currentTeam)->create();

    Epic::factory()->for($user->currentTeam)->for($status)->create(['title' => 'Epic 1']);

    $this->actingAs($user);

    Livewire::test('epics.index')
        ->set('selectedSquadIds', [$squad->id])
        ->set('selectedStatusIds', [$status->id])
        ->call('clearFilters')
        ->assertSet('selectedSquadIds', [])
        ->assertSet('selectedStatusIds', []);
});

it('can sort epics by created_at descending', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();

    $epic1 = Epic::factory()->for($user->currentTeam)->for($status)->create([
        'title' => 'First Epic',
        'created_at' => now()->subDays(2),
    ]);
    $epic2 = Epic::factory()->for($user->currentTeam)->for($status)->create([
        'title' => 'Second Epic',
        'created_at' => now()->subDay(),
    ]);
    $epic3 = Epic::factory()->for($user->currentTeam)->for($status)->create([
        'title' => 'Third Epic',
        'created_at' => now(),
    ]);

    $this->actingAs($user);

    $component = Livewire::test('epics.index')
        ->set('sortBy', 'created_at')
        ->set('sortDirection', 'desc');

    $epics = $component->viewData('epics');
    expect($epics->first()->title)->toBe('Third Epic');
    expect($epics->last()->title)->toBe('First Epic');
});

it('can sort epics by created_at ascending', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();

    $epic1 = Epic::factory()->for($user->currentTeam)->for($status)->create([
        'title' => 'First Epic',
        'created_at' => now()->subDays(2),
    ]);
    $epic2 = Epic::factory()->for($user->currentTeam)->for($status)->create([
        'title' => 'Second Epic',
        'created_at' => now()->subDay(),
    ]);
    $epic3 = Epic::factory()->for($user->currentTeam)->for($status)->create([
        'title' => 'Third Epic',
        'created_at' => now(),
    ]);

    $this->actingAs($user);

    $component = Livewire::test('epics.index')
        ->set('sortBy', 'created_at')
        ->set('sortDirection', 'asc');

    $epics = $component->viewData('epics');
    expect($epics->first()->title)->toBe('First Epic');
    expect($epics->last()->title)->toBe('Third Epic');
});

it('can sort epics by updated_at', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();

    $epic1 = Epic::factory()->for($user->currentTeam)->for($status)->create([
        'title' => 'Epic A',
        'updated_at' => now()->subDays(2),
    ]);
    $epic2 = Epic::factory()->for($user->currentTeam)->for($status)->create([
        'title' => 'Epic B',
        'updated_at' => now(),
    ]);

    $this->actingAs($user);

    $component = Livewire::test('epics.index')
        ->set('sortBy', 'updated_at')
        ->set('sortDirection', 'desc');

    $epics = $component->viewData('epics');
    expect($epics->first()->title)->toBe('Epic B');
    expect($epics->last()->title)->toBe('Epic A');
});

it('can sort epics by start_date', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();

    $epic1 = Epic::factory()->for($user->currentTeam)->for($status)->create([
        'title' => 'Epic A',
        'start_date' => '2025-03-01',
    ]);
    $epic2 = Epic::factory()->for($user->currentTeam)->for($status)->create([
        'title' => 'Epic B',
        'start_date' => '2025-01-01',
    ]);
    $epic3 = Epic::factory()->for($user->currentTeam)->for($status)->create([
        'title' => 'Epic C',
        'start_date' => null,
    ]);

    $this->actingAs($user);

    $component = Livewire::test('epics.index')
        ->set('sortBy', 'start_date')
        ->set('sortDirection', 'asc');

    $epics = $component->viewData('epics');
    expect($epics->first()->title)->toBe('Epic B');
    expect($epics->last()->title)->toBe('Epic C');
});

it('can sort epics by end_date', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();

    $epic1 = Epic::factory()->for($user->currentTeam)->for($status)->create([
        'title' => 'Epic A',
        'end_date' => '2025-12-31',
    ]);
    $epic2 = Epic::factory()->for($user->currentTeam)->for($status)->create([
        'title' => 'Epic B',
        'end_date' => '2025-06-30',
    ]);

    $this->actingAs($user);

    $component = Livewire::test('epics.index')
        ->set('sortBy', 'end_date')
        ->set('sortDirection', 'asc');

    $epics = $component->viewData('epics');
    expect($epics->first()->title)->toBe('Epic B');
    expect($epics->last()->title)->toBe('Epic A');
});

it('can sort epics by title ascending', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();

    Epic::factory()->for($user->currentTeam)->for($status)->create(['title' => 'Zebra Epic']);
    Epic::factory()->for($user->currentTeam)->for($status)->create(['title' => 'Alpha Epic']);
    Epic::factory()->for($user->currentTeam)->for($status)->create(['title' => 'Beta Epic']);

    $this->actingAs($user);

    $component = Livewire::test('epics.index')
        ->set('sortBy', 'title')
        ->set('sortDirection', 'asc');

    $epics = $component->viewData('epics');
    expect($epics->first()->title)->toBe('Alpha Epic');
    expect($epics->last()->title)->toBe('Zebra Epic');
});

it('can sort epics by title descending', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();

    Epic::factory()->for($user->currentTeam)->for($status)->create(['title' => 'Zebra Epic']);
    Epic::factory()->for($user->currentTeam)->for($status)->create(['title' => 'Alpha Epic']);
    Epic::factory()->for($user->currentTeam)->for($status)->create(['title' => 'Beta Epic']);

    $this->actingAs($user);

    $component = Livewire::test('epics.index')
        ->set('sortBy', 'title')
        ->set('sortDirection', 'desc');

    $epics = $component->viewData('epics');
    expect($epics->first()->title)->toBe('Zebra Epic');
    expect($epics->last()->title)->toBe('Alpha Epic');
});

it('can sort epics by priority', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();

    Epic::factory()->for($user->currentTeam)->for($status)->create(['title' => 'Low Epic', 'priority' => 'low']);
    Epic::factory()->for($user->currentTeam)->for($status)->create(['title' => 'High Epic', 'priority' => 'high']);
    Epic::factory()->for($user->currentTeam)->for($status)->create(['title' => 'Medium Epic', 'priority' => 'medium']);

    $this->actingAs($user);

    $component = Livewire::test('epics.index')
        ->set('sortBy', 'priority')
        ->set('sortDirection', 'desc');

    $epics = $component->viewData('epics');
    expect($epics->first()->title)->toBe('High Epic');
    expect($epics->get(1)->title)->toBe('Medium Epic');
    expect($epics->last()->title)->toBe('Low Epic');
});

it('toggles sort direction when clicking same sort field', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user);

    Livewire::test('epics.index')
        ->set('sortBy', 'title')
        ->set('sortDirection', 'asc')
        ->call('setSortBy', 'title')
        ->assertSet('sortDirection', 'desc')
        ->call('setSortBy', 'title')
        ->assertSet('sortDirection', 'asc');
});

it('shows empty state when filters return no results', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status1 = Status::where('slug', 'not-started')->first();
    $status2 = Status::where('slug', 'completed')->first();
    $squad = Squad::factory()->for($user->currentTeam)->create();

    $epic = Epic::factory()->for($user->currentTeam)->for($status1)->create(['title' => 'Test Epic']);
    $epic->squads()->attach($squad);

    $this->actingAs($user);

    Livewire::test('epics.index')
        ->set('selectedStatusIds', [$status2->id])
        ->assertSee('No epics match your filters')
        ->assertDontSee('Test Epic');
});

it('persists filters in url query parameters', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $squad = Squad::factory()->for($user->currentTeam)->create();
    $status = Status::first();

    $this->actingAs($user);

    Livewire::test('epics.index')
        ->set('selectedSquadIds', [$squad->id])
        ->set('selectedStatusIds', [$status->id])
        ->set('sortBy', 'title')
        ->set('sortDirection', 'asc')
        ->assertSetStrict('selectedSquadIds', [$squad->id])
        ->assertSetStrict('selectedStatusIds', [$status->id])
        ->assertSet('sortBy', 'title')
        ->assertSet('sortDirection', 'asc');
});

it('displays priority badge on epics with priority', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();

    Epic::factory()->for($user->currentTeam)->for($status)->create([
        'title' => 'High Priority Epic',
        'priority' => 'high',
    ]);

    $this->actingAs($user)
        ->get('/epics')
        ->assertSee('High Priority Epic')
        ->assertSee('High Priority');
});

it('displays story count on epics with stories', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();
    $squad = Squad::factory()->for($user->currentTeam)->create();

    $epic = Epic::factory()->for($user->currentTeam)->for($status)->create(['title' => 'Epic with Stories']);

    \App\Models\Story::factory()->count(3)->for($epic)->for($squad)->for($status)->create();

    $this->actingAs($user)
        ->get('/epics')
        ->assertSee('Epic with Stories')
        ->assertSee('3 stories');
});

it('displays last updated information on epics', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $status = Status::first();

    Epic::factory()->for($user->currentTeam)->for($status)->create([
        'title' => 'Recently Updated Epic',
        'updated_at' => now()->subHours(2),
    ]);

    $this->actingAs($user)
        ->get('/epics')
        ->assertSee('Recently Updated Epic')
        ->assertSee('Updated');
});
