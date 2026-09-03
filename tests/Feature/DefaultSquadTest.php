<?php

use App\Models\Engineer;
use App\Models\Epic;
use App\Models\EpicQuarterPlan;
use App\Models\Squad;
use App\Models\Status;
use App\Models\User;
use App\Support\DefaultSquad;
use Livewire\Livewire;

/**
 * A default squad is a starting value, never a lock: pages open on it, and
 * whatever the user picks after that wins.
 */
beforeEach(function () {
    $user = User::factory()->withPersonalTeam()->create();
    $this->user = $user;
    $this->team = $user->currentTeam;

    $this->charging = Squad::create(['team_id' => $this->team->id, 'name' => 'Charging', 'color' => '#EF4444']);
    $this->payments = Squad::create(['team_id' => $this->team->id, 'name' => 'Payments', 'color' => '#10B981']);

    $this->status = Status::create(['team_id' => $this->team->id, 'name' => 'Backlog', 'color' => '#71717A', 'is_default' => true]);

    $this->linkTo = fn (Squad $squad) => Engineer::create([
        'team_id' => $this->team->id,
        'squad_id' => $squad->id,
        'user_id' => $this->user->id,
        'name' => 'Me',
        'default_weekly_points' => 10,
    ]);

    $this->epicIn = function (string $title, Squad $squad) {
        $epic = Epic::create(['team_id' => $this->team->id, 'title' => $title, 'status_id' => $this->status->id]);
        EpicQuarterPlan::create(['epic_id' => $epic->id, 'squad_id' => $squad->id, 'year' => now()->year, 'quarter' => (int) ceil(now()->month / 3)]);

        return $epic;
    };

    $this->actingAs($user);
});

// ---------------------------------------------------------------- resolution

it('has no default for a login with no engineer record and no choice', function () {
    expect(DefaultSquad::for($this->user, $this->team))->toBeNull();
});

it('infers the default from the engineer linked to the login', function () {
    ($this->linkTo)($this->charging);

    expect(DefaultSquad::id($this->user, $this->team))->toBe($this->charging->id);
});

it('lets an explicit choice beat the inferred one', function () {
    ($this->linkTo)($this->charging);
    DefaultSquad::set($this->user, $this->team, $this->payments);

    expect(DefaultSquad::id($this->user, $this->team))->toBe($this->payments->id);
});

it('treats an explicit "none" as no default even with an engineer link', function () {
    ($this->linkTo)($this->charging);
    DefaultSquad::set($this->user, $this->team, null);

    expect(DefaultSquad::for($this->user, $this->team))->toBeNull();
});

it('keeps defaults separate per team', function () {
    DefaultSquad::set($this->user, $this->team, $this->charging);

    $other = \App\Models\Team::factory()->create(['user_id' => $this->user->id]);

    expect(DefaultSquad::for($this->user, $other))->toBeNull();
});

// ----------------------------------------------------------------- the pill

it('offers to make the selected squad the default, then reports it', function () {
    Livewire::test('default-squad', ['selected' => $this->charging->id])
        ->assertSee('Make Charging my default')
        ->call('makeDefault')
        ->assertSee('Charging is your default');

    expect(DefaultSquad::id($this->user, $this->team))->toBe($this->charging->id);
});

it('clears the default from the pill', function () {
    ($this->linkTo)($this->charging);

    Livewire::test('default-squad', ['selected' => $this->charging->id])
        ->assertSee('Charging is your default')
        ->call('clearDefault')
        ->assertSee('Make Charging my default');

    expect(DefaultSquad::for($this->user, $this->team))->toBeNull();
});

it('will not adopt a squad from another team', function () {
    $foreign = Squad::create(['team_id' => \App\Models\Team::factory()->create()->id, 'name' => 'Elsewhere', 'color' => '#000']);

    Livewire::test('default-squad', ['selected' => $foreign->id])
        ->assertDontSee('my default')
        ->call('makeDefault');

    expect(DefaultSquad::for($this->user, $this->team))->toBeNull();
});

// ------------------------------------------------------------- page openers

it('opens the board on the default squad only when the session has nothing', function () {
    DefaultSquad::set($this->user, $this->team, $this->charging);

    expect(Livewire::test('now')->get('squadFilter'))->toBe((string) $this->charging->id);

    session()->put('now.squad', '');

    expect(Livewire::test('now')->get('squadFilter'))->toBe('');
});

it('opens the epics list on the default squad unless the URL says otherwise', function () {
    DefaultSquad::set($this->user, $this->team, $this->charging);
    ($this->epicIn)('Charging thing', $this->charging);
    ($this->epicIn)('Payments thing', $this->payments);

    Livewire::test('epics.index')
        ->assertSet('selectedSquadIds', [(string) $this->charging->id])
        ->assertSee('Charging thing')
        ->assertDontSee('Payments thing');

    Livewire::withQueryParams(['selectedSquadIds' => [(string) $this->payments->id]])
        ->test('epics.index')
        ->assertSet('selectedSquadIds', [(string) $this->payments->id])
        ->assertSee('Payments thing')
        ->assertDontSee('Charging thing');
});

it('opens the matrix, planning grid, and roadmap on the default squad', function () {
    DefaultSquad::set($this->user, $this->team, $this->charging);
    $id = (string) $this->charging->id;

    Livewire::test('matrix')->assertSet('selectedSquadIds', [$id]);
    Livewire::test('planning.grid')->assertSet('squadIds', [$id]);
    Livewire::test('roadmap.calendar')->assertSet('selected_squads', [$id]);
});

// -------------------------------------------------------------- engineers

it('filters the engineers page by squad and opens on the default', function () {
    Engineer::create(['team_id' => $this->team->id, 'squad_id' => $this->charging->id, 'name' => 'Sarah Chen', 'default_weekly_points' => 10]);
    Engineer::create(['team_id' => $this->team->id, 'squad_id' => $this->payments->id, 'name' => 'Priya Nair', 'default_weekly_points' => 10]);

    Livewire::test('engineers.index')
        ->assertSee('Sarah Chen')
        ->assertSee('Priya Nair')
        ->set('squadIds', [(string) $this->payments->id])
        ->assertSee('Priya Nair')
        ->assertDontSee('Sarah Chen');

    DefaultSquad::set($this->user, $this->team, $this->charging);

    Livewire::test('engineers.index')
        ->assertSet('squadIds', [(string) $this->charging->id])
        ->assertSee('Sarah Chen')
        ->assertDontSee('Priya Nair');
});
