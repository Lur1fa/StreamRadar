<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('Account', [
            'account' => $request->user()->toPublicArray(),
            'provider' => $request->session()->get('auth.provider', 'local'),
            'passwordMinLength' => (int) config('security.password_min_length', 12),
        ]);
    }

    /**
     * Change the signed-in user's own password. Requires the current password
     * and signs out every other session of this user.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasLocalPassword()) {
            return back()->with('error', 'This account signs in through single sign-on and has no local password.');
        }

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min((int) config('security.password_min_length', 12))],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'The current password is incorrect.']);
        }

        $user->forceFill(['password' => $data['password']])->save();

        Auth::logoutOtherDevices($data['password']);
        $request->session()->regenerate();

        return back()->with('success', 'Password updated. Other sessions have been signed out.');
    }
}
