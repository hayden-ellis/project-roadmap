<?php

use App\Models\Epic;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

/**
 * The admin panel is a second, install-wide view of data that is otherwise
 * fenced by team. The tests pin the fence at the door (non-admins see a 404,
 * not a locked page) and the two guard rails on its actions: an admin cannot
 * delete themselves, and a team owner cannot be removed from their own team.
 */
beforeEach(function () {
    $this->admin = User::factory()->withPersonalTeam()->create(['name' => 'Ada Admin']);
    $this->admin->forceFill(['is_super_admin' => true])->save();

    $this->member = User::factory()->withPersonalTeam()->create(['name' => 'Mel Member', 'email' => 'mel@example.com']);
    $this->team = $this->admin->currentTeam;
    $this->team->update(['name' => 'Platform']);
    $this->team->users()->attach($this->member, ['role' => 'editor']);

    Epic::create(['team_id' => $this->team->id, 'title' => 'Checkout Redesign']);
});

it('hides the panel from ordinary users with a 404', function () {
    $this->actingAs($this->member)->get('/admin')->assertNotFound();
    $this->actingAs($this->member)->get('/admin/users')->assertNotFound();
    $this->actingAs($this->member)->get('/admin/teams')->assertNotFound();
    $this->actingAs($this->member)->get("/admin/teams/{$this->team->id}")->assertNotFound();
});

it('sends guests to login', function () {
    $this->get('/admin')->assertRedirect('/login');
});

it('shows the admin link only to super admins', function () {
    $this->actingAs($this->admin)->get('/now')->assertSee('data-test="admin-link"', false);
    $this->actingAs($this->member)->get('/now')->assertDontSee('data-test="admin-link"', false);
});

it('renders the overview with install-wide counts', function () {
    $this->actingAs($this->admin)->get('/admin')
        ->assertOk()
        ->assertSee('Overview')
        ->assertSeeInOrder(['data-test="stat-users"', '2'], false)
        ->assertSeeInOrder(['data-test="stat-teams"', '2'], false);
});

it('lists every user with their teams and roles', function () {
    $this->actingAs($this->admin)->get('/admin/users')
        ->assertOk()
        ->assertSee('Ada Admin')
        ->assertSee('Mel Member')
        ->assertSee('Platform · owner')
        ->assertSee('Platform · editor')
        ->assertSee('Super admin');
});

it('searches users by name or email', function () {
    $this->actingAs($this->admin);

    Livewire::test('admin.users')
        ->set('search', 'mel@')
        ->assertSee('Mel Member')
        ->assertDontSee('Ada Admin');
});

it('revokes a user\'s tokens', function () {
    $this->member->createToken('claude-code', ['mcp:read']);
    $this->actingAs($this->admin);

    Livewire::test('admin.users')->call('revokeTokens', $this->member->id);

    expect($this->member->tokens()->count())->toBe(0);
});

it('deletes a user after confirmation', function () {
    $this->actingAs($this->admin);

    Livewire::test('admin.users')
        ->call('confirmDelete', $this->member->id)
        ->assertSet('deletingUserId', $this->member->id)
        ->call('delete')
        ->assertSet('deletingUserId', null);

    expect(User::find($this->member->id))->toBeNull()
        ->and($this->team->fresh()->users()->count())->toBe(0);
});

it('refuses to let an admin delete themselves', function () {
    $this->actingAs($this->admin);

    Livewire::test('admin.users')
        ->call('confirmDelete', $this->admin->id)
        ->assertSet('deletingUserId', null);

    Livewire::test('admin.users')
        ->set('deletingUserId', $this->admin->id)
        ->call('delete')
        ->assertForbidden();

    expect(User::find($this->admin->id))->not->toBeNull();
});

it('lists every team with counts', function () {
    $this->actingAs($this->admin)->get('/admin/teams')
        ->assertOk()
        ->assertSee('Platform')
        ->assertSee($this->member->currentTeam->name)
        ->assertSee('Ada Admin');
});

it('shows a team\'s members and removes one after confirmation', function () {
    $this->actingAs($this->admin)->get("/admin/teams/{$this->team->id}")
        ->assertOk()
        ->assertSee('Mel Member')
        ->assertSee('editor')
        ->assertSee('Owner');

    Livewire::test('admin.team', ['team' => $this->team])
        ->call('confirmRemove', $this->member->id)
        ->assertSet('removingUserId', $this->member->id)
        ->call('remove')
        ->assertSet('removingUserId', null);

    expect($this->team->fresh()->hasUser($this->member))->toBeFalse()
        ->and(User::find($this->member->id))->not->toBeNull();
});

it('refuses to remove the team owner', function () {
    $this->actingAs($this->admin);

    Livewire::test('admin.team', ['team' => $this->team])
        ->call('confirmRemove', $this->admin->id)
        ->assertSet('removingUserId', null);

    Livewire::test('admin.team', ['team' => $this->team])
        ->set('removingUserId', $this->admin->id)
        ->call('remove')
        ->assertForbidden();
});

it('stops answering actions once the flag is revoked mid-session', function () {
    $this->actingAs($this->admin);
    $this->member->createToken('claude-code', ['mcp:read']);

    $page = Livewire::test('admin.users');

    $this->admin->forceFill(['is_super_admin' => false])->save();

    $page->call('revokeTokens', $this->member->id)->assertNotFound();

    expect($this->member->tokens()->count())->toBe(1);
});

it('records the most recent login and shows it to admins', function () {
    expect($this->member->last_login_at)->toBeNull();

    $this->post('/login', ['email' => $this->member->email, 'password' => 'password'])
        ->assertRedirect();

    $stamp = $this->member->fresh()->last_login_at;
    expect($stamp)->not->toBeNull()
        ->and($stamp->isAfter(now()->subMinute()))->toBeTrue();

    auth()->logout();

    $this->actingAs($this->admin)->get('/admin/users')
        ->assertOk()
        ->assertSeeInOrder(['Ada Admin', 'Never', 'Mel Member', 'ago'], false);
});

it('grants and revokes super admin from the console', function () {
    $this->artisan('admin:grant', ['email' => $this->member->email])
        ->expectsOutputToContain('is now a super admin')
        ->assertSuccessful();

    expect($this->member->fresh()->isSuperAdmin())->toBeTrue();

    $this->artisan('admin:grant', ['email' => $this->member->email, '--revoke' => true])
        ->assertSuccessful();

    expect($this->member->fresh()->isSuperAdmin())->toBeFalse();

    $this->artisan('admin:grant', ['email' => 'nobody@example.com'])->assertFailed();
});

it('never mass-assigns the flag', function () {
    $this->member->fill(['name' => 'Renamed', 'is_super_admin' => true])->save();

    expect($this->member->fresh()->name)->toBe('Renamed')
        ->and($this->member->fresh()->isSuperAdmin())->toBeFalse();
});
