<?php

use App\Models\User;

it('derives initials from full name', function (): void {
    $user = new User([
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);

    expect($user->initials())->toBe('JD');
});

it('uses single initial when only one name part is present', function (): void {
    $user = new User([
        'name' => 'jane',
        'email' => 'jane@example.com',
    ]);

    expect($user->initials())->toBe('J');
});

it('handles hyphen/underscore and multiple spaces', function (): void {
    $user = new User([
        'name' => '  Mary-Jane   Watson  ',
        'email' => 'mj@example.com',
    ]);

    expect($user->initials())->toBe('MW');
});

it('falls back to email first letter when name is empty', function (): void {
    $user = new User([
        'name' => '',
        'email' => 'foo@example.com',
    ]);

    expect($user->initials())->toBe('F');
});

it('returns empty string when both name and email are missing', function (): void {
    $user = new User;

    expect($user->initials())->toBe('');
});
