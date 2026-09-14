<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;

/**
 * Stamps the user on every fresh sign-in: password, Google, and remember-me
 * cookie restores all fire Login. Ordinary session requests do not, so this
 * is "last login", not "last seen".
 */
class RecordLastLogin
{
    public function handle(Login $event): void
    {
        $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
    }
}
