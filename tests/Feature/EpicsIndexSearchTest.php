<?php

use App\Models\Category;
use App\Models\Epic;
use App\Models\Squad;
use App\Models\Status;
use App\Models\User;
use Livewire\Livewire;

/**
 * The epics list has a search box in front of its filters. These cover what
 * the box matches, how it combines with the filters, and the chips that show
 * what is applied.
 */
beforeEach(function () {
    $user = User::factory()->withPersonalTeam()->create();
    $this->user = $user;
    $this->team = $user->currentTeam;

    $this->backlog = Status::create(['team_id' => $this->team->id, 'name' => 'Backlog', 'color' => '#71717A', 'is_default' => true]);
    $this->building = Status::create(['team_id' => $this->team->id, 'name' => 'Building', 'color' => '#22C55E']);

    $this->scheduler = Epic::create([
        'team_id' => $this->team->id, 'title' => 'Smart Charging Scheduler', 'status_id' => $this->building->id,
        'jira_epic_url' => 'https://acme.atlassian.net/browse/CHG-142',
    ]);
    $this->dashboard = Epic::create([
        'team_id' => $this->team->id, 'title' => 'Fleet dashboard rebuild', 'status_id' => $this->backlog->id,
        'jira_epic_url' => 'https://acme.atlassian.net/browse/PLT-88',
    ]);

    $this->actingAs($user);
});

it('matches the title, case-insensitively', function () {
    Livewire::test('epics.index')
        ->set('search', 'charging')
        ->assertSee('Smart Charging Scheduler')
        ->assertDontSee('Fleet dashboard rebuild');
});

it('matches the Jira key through the link', function () {
    Livewire::test('epics.index')
        ->set('search', 'PLT-88')
        ->assertSee('Fleet dashboard rebuild')
        ->assertDontSee('Smart Charging Scheduler');
});

it('treats like wildcards as plain text', function () {
    Livewire::test('epics.index')
        ->set('search', '%')
        ->assertDontSee('Smart Charging Scheduler')
        ->assertDontSee('Fleet dashboard rebuild');
});

it('narrows within the filters rather than replacing them', function () {
    Livewire::test('epics.index')
        ->set('selectedStatusIds', [(string) $this->backlog->id])
        ->set('search', 'Scheduler')
        ->assertSee('No epics match')
        ->assertSee('with these filters');
});

it('offers to clear both search and filters from the empty state', function () {
    Livewire::test('epics.index')
        ->set('selectedStatusIds', [(string) $this->backlog->id])
        ->set('search', 'Scheduler')
        ->call('clearSearchAndFilters')
        ->assertSet('search', '')
        ->assertSet('selectedStatusIds', [])
        ->assertSee('Smart Charging Scheduler')
        ->assertSee('Fleet dashboard rebuild');
});

it('shows a chip for each applied filter and removes one at a time', function () {
    $squad = Squad::create(['team_id' => $this->team->id, 'name' => 'Charging', 'color' => '#F59E0B']);
    $category = Category::create(['team_id' => $this->team->id, 'name' => 'Growth']);

    $component = Livewire::test('epics.index')
        ->set('selectedStatusIds', [(string) $this->backlog->id, (string) $this->building->id])
        ->set('selectedCategoryIds', [(string) $category->id])
        ->assertSee('Remove Status Backlog')
        ->assertSee('Remove Status Building')
        ->assertSee('Remove Category Growth')
        ->assertDontSee('Remove Squad Charging')
        ->call('removeFilter', 'selectedStatusIds', (string) $this->backlog->id)
        ->assertSet('selectedStatusIds', [(string) $this->building->id])
        ->assertDontSee('Remove Status Backlog');

    // Only the three filter lists can be edited this way.
    $component->call('removeFilter', 'search', 'x')->assertSet('selectedStatusIds', [(string) $this->building->id]);
});

it('starts a newly picked sort field in its natural direction', function () {
    Livewire::test('epics.index')
        ->assertSet('sortDirection', 'desc')
        ->set('sortBy', 'title')
        ->assertSet('sortDirection', 'asc')
        ->set('sortBy', 'priority')
        ->assertSet('sortDirection', 'desc');
});

it('keeps the search in the URL', function () {
    Livewire::withQueryParams(['search' => 'fleet'])
        ->test('epics.index')
        ->assertSet('search', 'fleet')
        ->assertSee('Fleet dashboard rebuild')
        ->assertDontSee('Smart Charging Scheduler');
});
