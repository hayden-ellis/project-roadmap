<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;

/**
 * Anyone can mint their own MCP token from settings. The password is asked
 * for again first, the secret is shown once, write access follows the
 * person's team role, and only their own tokens are theirs to revoke.
 */
beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;

    $this->confirmed = fn () => $this->withSession(['auth.password_confirmed_at' => time()]);

    $this->actingAs($this->user);
});

it('has its own settings page', function () {
    $this->get('/settings/claude-code')
        ->assertOk()
        ->assertSee('Mint token')
        ->assertSee('No tokens yet');
});

it('links to the page from the other settings pages', function () {
    $this->get('/settings/security')->assertSee('/settings/claude-code');
});

it('mints a read token and shows the secret and the connect command once', function () {
    ($this->confirmed)();

    $component = Livewire::test('settings.claude-code')
        ->set('name', 'work laptop')
        ->call('create')
        ->assertHasNoErrors()
        ->assertSee('Your new token')
        ->assertSee('claude mcp add --transport http roadmap');

    $token = $this->user->tokens()->sole();

    expect($token->name)->toBe('work laptop')
        ->and($token->abilities)->toBe(['mcp:read'])
        ->and($token->expires_at)->not->toBeNull()
        ->and($component->get('plainTextToken'))->toStartWith($token->id.'|');

    $component->call('dismiss')->assertDontSee('Your new token');
});

it('mints a write token for someone who can edit epics', function () {
    ($this->confirmed)();

    Livewire::test('settings.claude-code')
        ->set('access', 'write')
        ->call('create')
        ->assertHasNoErrors()
        ->assertSee('read and write');

    expect($this->user->tokens()->sole()->abilities)->toBe(['mcp:read', 'mcp:write']);
});

it('will not mint a write token for a role without update rights', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $owner->currentTeam->users()->attach($this->user, ['role' => 'viewer']);
    $this->user->switchTeam($owner->currentTeam);

    ($this->confirmed)();

    Livewire::test('settings.claude-code')
        ->assertSee('Needs an editor or admin role')
        ->set('access', 'write')
        ->call('create')
        ->assertHasNoErrors();

    expect($this->user->tokens()->sole()->abilities)->toBe(['mcp:read']);
});

it('refuses to mint without a fresh password confirmation', function () {
    Livewire::test('settings.claude-code')
        ->call('create')
        ->assertForbidden();

    expect($this->user->tokens()->count())->toBe(0);
});

it('requires a name', function () {
    ($this->confirmed)();

    Livewire::test('settings.claude-code')
        ->set('name', '')
        ->call('create')
        ->assertHasErrors(['name']);
});

it('lists tokens with their access and revokes them', function () {
    $read = $this->user->createToken('hayden-mbp', ['mcp:read'])->accessToken;
    $write = $this->user->createToken('build-box', ['mcp:read', 'mcp:write'])->accessToken;

    Livewire::test('settings.claude-code')
        ->assertSeeInOrder(['build-box', 'read + write', 'hayden-mbp', 'read only'])
        ->call('revoke', $read->id)
        ->assertDontSee('hayden-mbp');

    expect($this->user->tokens()->pluck('id')->all())->toBe([$write->id]);
});

it('cannot revoke another user\'s token', function () {
    $other = User::factory()->withPersonalTeam()->create();
    $theirs = $other->createToken('theirs', ['mcp:read'])->accessToken;

    Livewire::test('settings.claude-code')
        ->call('revoke', $theirs->id)
        ->assertNotFound();

    expect($other->tokens()->count())->toBe(1);
});

it('mints a secret that Sanctum resolves back to the user', function () {
    ($this->confirmed)();

    $plain = Livewire::test('settings.claude-code')->call('create')->get('plainTextToken');

    $token = PersonalAccessToken::findToken($plain);

    expect($token)->not->toBeNull()
        ->and($token->tokenable->is($this->user))->toBeTrue()
        ->and($token->can('mcp:read'))->toBeTrue()
        ->and($token->can('mcp:write'))->toBeFalse();
});
