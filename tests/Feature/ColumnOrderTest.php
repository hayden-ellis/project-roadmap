<?php

use App\Models\Status;
use App\Models\User;
use App\Models\UserColumnOrder;
use App\Support\ColumnOrder;
use Livewire\Livewire;

/**
 * The team order from /statuses is the default. Dragging a column on the
 * board gives that one user their own arrangement, which never touches the
 * team's or anyone else's.
 */
beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;

    $make = fn (string $name) => Status::create(['team_id' => $this->team->id, 'name' => $name, 'color' => '#71717A']);

    $this->backlog = $make('Backlog');
    $this->doing = $make('Doing');
    $this->paused = $make('Paused');
    $this->shipped = $make('Shipped');

    $this->actingAs($this->user);

    $this->columnIds = fn ($component) => $component->viewData('columns')->map(fn ($c) => $c['status']->id)->all();
});

test('a user who has never dragged sees the team order', function () {
    expect(($this->columnIds)(Livewire::test('now')))
        ->toBe([$this->backlog->id, $this->doing->id, $this->paused->id, $this->shipped->id]);
});

test('dragging a column reorders the board for that user', function () {
    $component = Livewire::test('now')->call('moveColumn', $this->shipped->id, 0);

    expect(($this->columnIds)($component))
        ->toBe([$this->shipped->id, $this->backlog->id, $this->doing->id, $this->paused->id]);

    // And the filter list follows the same order.
    expect($component->viewData('statuses')->pluck('id')->all())
        ->toBe([$this->shipped->id, $this->backlog->id, $this->doing->id, $this->paused->id]);
});

test('dragging to the end lands last', function () {
    $component = Livewire::test('now')->call('moveColumn', $this->backlog->id, 3);

    expect(($this->columnIds)($component))
        ->toBe([$this->doing->id, $this->paused->id, $this->shipped->id, $this->backlog->id]);
});

test('the order is the user\'s own, not the team\'s', function () {
    Livewire::test('now')->call('moveColumn', $this->shipped->id, 0);

    expect(Status::where('team_id', $this->team->id)->ordered()->pluck('id')->all())
        ->toBe([$this->backlog->id, $this->doing->id, $this->paused->id, $this->shipped->id]);

    $other = User::factory()->create();
    $this->team->users()->attach($other, ['role' => 'editor']);
    $other->switchTeam($this->team);

    $this->actingAs($other);

    expect(($this->columnIds)(Livewire::test('now')))
        ->toBe([$this->backlog->id, $this->doing->id, $this->paused->id, $this->shipped->id]);
});

test('the order survives a fresh session', function () {
    Livewire::test('now')->call('moveColumn', $this->paused->id, 0);

    session()->flush();

    expect(($this->columnIds)(Livewire::test('now')))
        ->toBe([$this->paused->id, $this->backlog->id, $this->doing->id, $this->shipped->id]);
});

test('a status created after the drag appears in its team position, after the saved ones', function () {
    Livewire::test('now')->call('moveColumn', $this->shipped->id, 0);

    $review = Status::create(['team_id' => $this->team->id, 'name' => 'Review', 'color' => '#71717A']);

    expect(($this->columnIds)(Livewire::test('now')))
        ->toBe([$this->shipped->id, $this->backlog->id, $this->doing->id, $this->paused->id, $review->id]);
});

test('a deleted status drops out of the saved order', function () {
    Livewire::test('now')->call('moveColumn', $this->shipped->id, 0);

    $this->doing->delete();

    expect(($this->columnIds)(Livewire::test('now')))
        ->toBe([$this->shipped->id, $this->backlog->id, $this->paused->id]);
});

test('a drop position counts visible columns only, and hidden ones keep their place', function () {
    // Board shows Backlog, Paused, Shipped. Drag Shipped to position 1: ahead
    // of Paused, which is after the hidden Doing.
    $component = Livewire::test('now')
        ->call('toggleColumn', $this->doing->id)
        ->call('moveColumn', $this->shipped->id, 1);

    expect(($this->columnIds)($component))
        ->toBe([$this->backlog->id, $this->shipped->id, $this->paused->id]);

    $component->call('showAllColumns');

    expect(($this->columnIds)($component))
        ->toBe([$this->backlog->id, $this->doing->id, $this->shipped->id, $this->paused->id]);
});

test('resetting returns to the team order and forgets the row', function () {
    $component = Livewire::test('now')->call('moveColumn', $this->shipped->id, 0);

    expect($component->viewData('customOrder'))->toBeTrue();

    $component->call('resetColumnOrder');

    expect($component->viewData('customOrder'))->toBeFalse()
        ->and(($this->columnIds)($component))
        ->toBe([$this->backlog->id, $this->doing->id, $this->paused->id, $this->shipped->id])
        ->and(UserColumnOrder::count())->toBe(0);
});

test('a column from another team cannot be dragged', function () {
    $foreign = Status::create(['team_id' => User::factory()->withPersonalTeam()->create()->currentTeam->id, 'name' => 'Theirs', 'color' => '#71717A']);

    Livewire::test('now')->call('moveColumn', $foreign->id, 0)->assertStatus(403);
});

test('the order is kept per team', function () {
    Livewire::test('now')->call('moveColumn', $this->shipped->id, 0);

    expect(ColumnOrder::isCustom($this->user, $this->team))->toBeTrue();

    $otherTeam = $this->user->ownedTeams()->create(['name' => 'Second', 'personal_team' => false]);

    expect(ColumnOrder::isCustom($this->user, $otherTeam))->toBeFalse();
});
