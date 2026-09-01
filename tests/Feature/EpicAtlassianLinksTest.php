<?php

use App\Models\Epic;
use App\Models\Status;
use App\Models\User;
use Livewire\Livewire;

/**
 * Atlassian links are pasted URLs on the epic, nothing synced. These cover
 * the autosave path on the edit page and the chips that surface them.
 */
beforeEach(function () {
    $user = User::factory()->withPersonalTeam()->create();
    $this->user = $user;
    $this->team = $user->currentTeam;

    $this->status = Status::create([
        'team_id' => $this->team->id, 'name' => 'In progress', 'color' => '#10B981', 'is_default' => true,
    ]);

    $this->epic = Epic::create([
        'team_id' => $this->team->id,
        'title' => 'Smart Charging Scheduler',
        'status_id' => $this->status->id,
        'priority' => 'medium',
    ]);

    $this->actingAs($user);
});

it('saves a pasted Jira epic link as soon as it changes', function () {
    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->set('jira_epic_url', 'https://acme.atlassian.net/browse/ROAD-42')
        ->assertDispatched('epic-saved');

    expect($this->epic->fresh())
        ->jira_epic_url->toBe('https://acme.atlassian.net/browse/ROAD-42')
        ->jiraEpicKey()->toBe('ROAD-42');
});

it('saves a pasted Product Discovery idea link', function () {
    $url = 'https://acme.atlassian.net/jira/polaris/projects/IDEAS/ideas/view/123?selectedIssue=IDEAS-7';

    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->set('jpd_idea_url', $url)
        ->assertDispatched('epic-saved');

    expect($this->epic->fresh())
        ->jpd_idea_url->toBe($url)
        ->jpdIdeaKey()->toBe('IDEAS-7');
});

it('clears a link when the field is emptied', function () {
    $this->epic->update(['jira_epic_url' => 'https://acme.atlassian.net/browse/ROAD-42']);

    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->set('jira_epic_url', '');

    expect($this->epic->fresh()->jira_epic_url)->toBeNull();
});

it('rejects something that is not an https link', function () {
    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->set('jira_epic_url', 'ROAD-42')
        ->assertHasErrors('jira_epic_url');

    expect($this->epic->fresh()->jira_epic_url)->toBeNull();
});

it('shows the issue key chips on the epics list', function () {
    $this->epic->update([
        'jira_epic_url' => 'https://acme.atlassian.net/browse/ROAD-42',
        'jpd_idea_url' => 'https://acme.atlassian.net/jira/polaris/projects/IDEAS/ideas/view/123?selectedIssue=IDEAS-7',
    ]);

    Livewire::test('epics.index')
        ->assertSee('ROAD-42')
        ->assertSee('IDEAS-7');
});

it('saves a pasted Jira link from the board flyout', function () {
    Livewire::test('now')
        ->call('open', $this->epic->id)
        ->set('editJiraEpicUrl', 'https://acme.atlassian.net/browse/ROAD-42')
        ->set('editJpdIdeaUrl', 'https://acme.atlassian.net/jira/polaris/projects/IDEAS/ideas/view/123?selectedIssue=IDEAS-7');

    expect($this->epic->fresh())
        ->jira_epic_url->toBe('https://acme.atlassian.net/browse/ROAD-42')
        ->jpdIdeaKey()->toBe('IDEAS-7');
});

it('clears a link emptied from the board flyout', function () {
    $this->epic->update(['jira_epic_url' => 'https://acme.atlassian.net/browse/ROAD-42']);

    Livewire::test('now')
        ->call('open', $this->epic->id)
        ->set('editJiraEpicUrl', '');

    expect($this->epic->fresh()->jira_epic_url)->toBeNull();
});

it('rejects a non-https link in the board flyout', function () {
    Livewire::test('now')
        ->call('open', $this->epic->id)
        ->set('editJiraEpicUrl', 'ROAD-42')
        ->assertHasErrors('editJiraEpicUrl');

    expect($this->epic->fresh()->jira_epic_url)->toBeNull();
});

it('links out to Jira from the board flyout', function () {
    $this->epic->update(['jira_epic_url' => 'https://acme.atlassian.net/browse/ROAD-42']);

    Livewire::test('now')
        ->call('open', $this->epic->id)
        ->assertSee('ROAD-42');
});
