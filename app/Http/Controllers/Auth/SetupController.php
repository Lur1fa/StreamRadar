<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\SetupTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Creates the very first administrator. Only reachable while the users
 * table is empty, and only with the one-time token printed by
 * `php artisan auth:setup-token` (the Docker entrypoint prints it on first
 * start). There is no default account and no default password.
 */
class SetupController extends Controller
{
    public function __construct(private SetupTokenService $setup) {}

    public function show(): Response
    {
        abort_unless($this->setup->isSetupRequired(), 404);

        return Inertia::render('Setup', [
            'tokenActive' => $this->setup->hasActiveToken(),
            'ttlMinutes' => $this->setup->ttlMinutes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->setup->isSetupRequired(), 404);

        $data = $request->validate([
            'setup_token' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'confirmed', Password::min((int) config('security.password_min_length', 12))],
        ]);

        if (! $this->setup->verify($data['setup_token'])) {
            Log::warning('Setup attempted with an invalid or expired token', ['ip' => $request->ip()]);

            return back()->withErrors(['setup_token' => 'The setup token is invalid or has expired. Generate a new one with: php artisan auth:setup-token'])->onlyInput('name', 'email');
        }

        $user = DB::transaction(function () use ($data) {
            // Re-check inside the transaction so two concurrent submissions cannot both succeed.
            abort_unless($this->setup->isSetupRequired(), 409, 'Setup has already been completed.');

            $user = User::create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => $data['password'],
                'role' => User::ROLE_ADMIN,
                'email_verified_at' => now(),
            ]);

            $this->setup->consume();

            return $user;
        });

        $request->session()->regenerate();
        Auth::login($user);
        $request->session()->put('auth.provider', 'local');
        $user->forceFill(['last_login_at' => now()])->save();

        Log::info('Initial administrator account created.', ['user_id' => $user->id]);

        return redirect('/')->with('success', 'Administrator account created. Welcome to StreamRadar!');
    }
}
