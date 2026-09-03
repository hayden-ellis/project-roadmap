<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;

/**
 * Always land users on the "now" page after signing in.
 *
 * Fortify's default response honours the session's intended URL, so a user
 * who hit /planning (bookmark, stale tab) while logged out would be bounced
 * back there instead of to /now. We deliberately ignore the intended URL.
 */
class LoginResponse implements LoginResponseContract, TwoFactorLoginResponseContract
{
    public function toResponse($request)
    {
        return $request->wantsJson()
            ? response()->json(['two_factor' => false])
            : redirect(Fortify::redirects('login'));
    }
}
