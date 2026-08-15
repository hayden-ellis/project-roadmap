<?php

use App\Models\Epic;
use App\Models\Status;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $user = User::factory()->withPersonalTeam()->create();
    $this->team = $user->currentTeam;

    $this->make = fn (string $name, array $attributes = []) => Status::create([
        'team_id' => $this->team->id,
        'name' => $name,
        'color' => '#71717A',
    ] + $attributes);

    $this->actingAs($user);
});

it('lists the team statuses in board order', function () {
    ($this->make)('Backlog');
    ($this->make)('In progress');
    ($this->make)('Shipped');

    Livewire::test('statuses.index')->assertViewHas(
        'statuses',
        fn ($s) => $s->pluck('name')->all() === ['Backlog', 'In progress', 'Shipped'],
    );
});

it('creates a status and makes the first one the default', function () {
    Livewire::test('statuses.index')
        ->call('startCreating')
        ->set('name', 'Backlog')
        ->set('color', '#8B5CF6')
        ->call('create')
        ->assertHasNoErrors();

    $status = Status::where('name', 'Backlog')->firstOrFail();

    expect($status->is_default)->toBeTrue()
        ->and($status->color)->toBe('#8B5CF6');
});

it('does not make later statuses the default', function () {
    ($this->make)('Backlog', ['is_default' => true]);

    Livewire::test('statuses.index')
        ->call('startCreating')
        ->set('name', 'In progress')
        ->call('create');

    expect(Status::where('name', 'In progress')->value('is_default'))->toBeFalse();
});

it('refuses a duplicate name on the same team', function () {
    ($this->make)('Backlog');

    Livewire::test('statuses.index')
        ->call('startCreating')
        ->set('name', 'Backlog')
        ->call('create')
        ->assertHasErrors('name');

    expect(Status::where('name', 'Backlog')->count())->toBe(1);
});

it('allows the same name on a different team', function () {
    ($this->make)('Backlog');

    $otherUser = User::factory()->withPersonalTeam()->create();
    Status::create(['team_id' => $otherUser->currentTeam->id, 'name' => 'Backlog', 'color' => '#000']);

    expect(Status::where('name', 'Backlog')->count())->toBe(2);
});

it('saves an inline edit as it changes', function () {
    $status = ($this->make)('Backlog');

    Livewire::test('statuses.index')
        ->call('edit', $status->id)
        ->set('name', 'Icebox')
        ->set('color', '#EC4899')
        ->set('is_complete', true)
        ->set('requires_reason', true)
        ->assertDispatched('status-saved');

    expect($status->fresh())
        ->name->toBe('Icebox')
        ->color->toBe('#EC4899')
        ->is_complete->toBeTrue()
        ->requires_reason->toBeTrue();
});

it('keeps an invalid rename out of the database', function () {
    $status = ($this->make)('Backlog');

    Livewire::test('statuses.index')
        ->call('edit', $status->id)
        ->set('name', '')
        ->assertHasErrors('name');

    expect($status->fresh()->name)->toBe('Backlog');
});

it('moves the default flag to exactly one status', function () {
    $backlog = ($this->make)('Backlog', ['is_default' => true]);
    $doing = ($this->make)('In progress');

    Livewire::test('statuses.index')->call('makeDefault', $doing->id);

    expect($backlog->fresh()->is_default)->toBeFalse()
        ->and($doing->fresh()->is_default)->toBeTrue();
});

it('reorders statuses by drag', function () {
    $a = ($this->make)('Backlog');
    $b = ($this->make)('In progress');
    $c = ($this->make)('Shipped');

    Livewire::test('statuses.index')->call('sort', $c->id, 0);

    expect(Status::where('team_id', $this->team->id)->ordered()->pluck('name')->all())
        ->toBe(['Shipped', 'Backlog', 'In progress']);
});

describe('deleting a status', function () {
    it('moves its epics to the chosen status', function () {
        $backlog = ($this->make)('Backlog');
        $doing = ($this->make)('In progress');

        $epic = Epic::create([
            'team_id' => $this->team->id,
            'title' => 'Wallet Top-ups',
            'status_id' => $backlog->id,
        ]);

        Livewire::test('statuses.index')
            ->call('confirmDeletion', $backlog->id)
            ->set('reassignTo', (string) $doing->id)
            ->call('delete');

        expect(Status::find($backlog->id))->toBeNull()
            ->and($epic->fresh()->status_id)->toBe($doing->id);
    });

    it('hands the default flag to the status that takes the epics', function () {
        $backlog = ($this->make)('Backlog', ['is_default' => true]);
        $doing = ($this->make)('In progress');

        Livewire::test('statuses.index')
            ->call('confirmDeletion', $backlog->id)
            ->set('reassignTo', (string) $doing->id)
            ->call('delete');

        expect($doing->fresh()->is_default)->toBeTrue();
    });

    it('refuses to delete the only status', function () {
        $only = ($this->make)('Backlog');

        Livewire::test('statuses.index')
            ->call('confirmDeletion', $only->id)
            ->call('delete')
            ->assertForbidden();

        expect(Status::count())->toBe(1);
    });
});

it('refuses to touch another team status', function () {
    $otherUser = User::factory()->withPersonalTeam()->create();
    $foreign = Status::create(['team_id' => $otherUser->currentTeam->id, 'name' => 'Theirs', 'color' => '#000']);

    Livewire::test('statuses.index')->call('edit', $foreign->id)->assertForbidden();
});

it('invites the first status when there are none', function () {
    Livewire::test('statuses.index')
        ->assertSee('No statuses yet')
        ->assertSee('Add the first status');
});
