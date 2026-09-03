<?php

use App\Models\User;
use Livewire\Livewire;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('now', absolute: false));
});

test('users cannot authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('the login form lands on now even when a protected page was requested first', function () {
    $user = User::factory()->create();

    // The browser signs in through the Livewire form, not Fortify's POST.
    $this->get('/planning')->assertRedirect(route('login', absolute: false));

    Livewire::test('auth.login')
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('now', absolute: false));

    $this->assertAuthenticated();
});

test('login lands on now even when a protected page was requested first', function () {
    $user = User::factory()->create();

    // Hitting a guarded page while logged out stores it as the intended URL.
    $this->get('/planning')->assertRedirect(route('login', absolute: false));

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('now', absolute: false));
});
