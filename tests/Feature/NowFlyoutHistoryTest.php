<?php

use App\Models\Epic;
use App\Models\EpicActivity;
use App\Models\Status;
use App\Models\User;
use Livewire\Livewire;

/**
 * The flyout's History tab. The rows themselves are the Epic model's doing
 * (see EpicHistoryTest); these cover what the board writes and what the
 * dialog shows.
 */
beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create(['name' => 'Ada Lovelace']);
    $this->team = $this->user->currentTeam;

    $make = fn (array $attributes) => Status::create(['team_id' => $this->team->id, 'color' => '#71717A'] + $attributes);

    $this->backlog = $make(['name' => 'Backlog', 'is_default' => true]);
    $this->doing = $make(['name' => 'In progress']);
    $this->shipped = $make(['name' => 'Shipped', 'is_complete' => true]);

    $this->makeEpic = fn (string $title = 'Checkout Redesign') => Epic::create([
        'team_id' => $this->team->id,
        'title' => $title,
        'status_id' => $this->backlog->id,
    ]);

    $this->actingAs($this->user);
});

it('shows the epic\'s history in the flyout', function () {
    $epic = ($this->makeEpic)();
    EpicActivity::factory()->create([
        'epic_id' => $epic->id,
        'user_id' => $this->user->id,
        'diff' => ['title' => ['from' => 'Checkout', 'to' => 'Checkout Redesign']],
    ]);

    Livewire::test('now')
        ->call('open', $epic->id)
        ->assertSet('flyoutTab', 'comments')
        ->assertSee('renamed it from "Checkout" to "Checkout Redesign"')
        ->assertSee('created this epic')
        ->tap(fn ($c) => expect($c->instance()->flyout['activityCount'])->toBe(2));
});

it('records the flyout\'s inline edits', function () {
    $epic = ($this->makeEpic)();

    Livewire::test('now')
        ->call('open', $epic->id)
        ->set('editTitle', 'Checkout v2')
        ->set('editPriority', 'critical');

    $rows = $epic->activities()->where('event', 'updated')->reorder('id')->get();

    expect($rows->map(fn ($row) => $row->lines()[0]['text'])->all())->toBe([
        'renamed it from "Checkout Redesign" to "Checkout v2"',
        'changed priority from Medium to Critical',
    ])->and($rows[0]->user_id)->toBe($this->user->id);
});

it('records a drag between columns by name', function () {
    $epic = ($this->makeEpic)();

    Livewire::test('now')->call('moveEpic', $epic->id, 0, $this->doing->id);

    expect($epic->activities()->first()->lines()[0]['text'])->toBe('moved it from Backlog to In progress');
});

it('records a drag within a column as nothing at all', function () {
    $first = ($this->makeEpic)('First');
    ($this->makeEpic)('Second');

    Livewire::test('now')->call('moveEpic', $first->id, 1, $this->backlog->id);

    expect($first->activities()->count())->toBe(1);
});

it('records shipping and reopening', function () {
    $epic = ($this->makeEpic)();

    Livewire::test('now')
        ->call('open', $epic->id)
        ->call('markShipped')
        ->call('reopen');

    $texts = $epic->activities()->where('event', 'updated')->reorder('id')->get()
        ->map(fn ($row) => $row->lines()[0]['text'])->all();

    expect($texts)->toBe(['moved it from Backlog to Shipped', 'moved it from Shipped to Backlog']);
});

it('never shows another team\'s history', function () {
    $stranger = User::factory()->withPersonalTeam()->create();
    $foreign = Epic::create(['team_id' => $stranger->currentTeam->id, 'title' => 'Their work']);
    EpicActivity::factory()->create([
        'epic_id' => $foreign->id,
        'user_id' => $stranger->id,
        'diff' => ['title' => ['from' => 'Secret', 'to' => 'Their work']],
    ]);

    $epic = ($this->makeEpic)();

    Livewire::test('now')
        ->call('open', $epic->id)
        ->assertDontSee('Secret')
        ->tap(fn ($c) => expect($c->instance()->flyout['activities']->pluck('epic_id')->unique()->all())->toBe([$epic->id]));

    Livewire::test('now')->call('open', $foreign->id)->assertForbidden();
});

it('caps the list until asked for all of it', function () {
    $epic = ($this->makeEpic)();
    EpicActivity::factory()->count(EpicActivity::RECENT + 5)->create(['epic_id' => $epic->id, 'user_id' => $this->user->id]);

    Livewire::test('now')
        ->call('open', $epic->id)
        ->tap(fn ($c) => expect($c->instance()->flyout['activities'])->toHaveCount(EpicActivity::RECENT))
        ->assertSee('Show all '.(EpicActivity::RECENT + 6))
        ->call('showAllHistory')
        ->tap(fn ($c) => expect($c->instance()->flyout['activities'])->toHaveCount(EpicActivity::RECENT + 6));
});

it('keeps the composer on the history tab and forgets the tab on close', function () {
    $epic = ($this->makeEpic)();

    Livewire::test('now')
        ->call('open', $epic->id)
        ->assertSee('Enter to send')
        ->set('flyoutTab', 'history')
        ->assertSee('Enter to send')
        ->call('close')
        ->assertSet('flyoutTab', 'comments')
        ->assertSet('showAllHistory', false);
});
