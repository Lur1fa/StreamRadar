<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Starts a signed-in session the same way for every sign-in path (password,
 * SSO, first-admin setup).
 */
final class SessionLogin
{
    public const PROVIDER_LOCAL = 'local';

    public const PROVIDER_OIDC = 'oidc';

    public static function start(Request $request, User $user, string $provider): void
    {
        // A fresh session id, so a session id planted before sign-in is worthless.
        $request->session()->regenerate();
        Auth::login($user, remember: false);
        // Read on sign-out to decide whether to end the SSO session too.
        $request->session()->put('auth.provider', $provider);
        $user->forceFill(['last_login_at' => now()])->save();
    }
}
