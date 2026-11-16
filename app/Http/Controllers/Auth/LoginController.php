<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\LoginRateLimiter;
use App\Services\Auth\OidcService;
use App\Services\Auth\SetupTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class LoginController extends Controller
{
    /**
     * A real bcrypt hash that matches nothing. Checked when the submitted
     * e-mail is unknown or the account has no password, so the response time
     * does not reveal whether an account exists.
     */
    private const DUMMY_HASH = '$2y$12$5P7fq8Fw0Y2m9yZk3nKxOe3L7i0yqVvN8KxGJH2Qm4dYwC1tQp9Qa';

    private const GENERIC_ERROR = 'These credentials do not match our records.';

    public function __construct(
        private LoginRateLimiter $limiter,
        private OidcService $oidc,
        private SetupTokenService $setup,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        if ($this->setup->isSetupRequired() && ! $this->oidc->isConfigured()) {
            return redirect()->route('setup.show');
        }

        return Inertia::render('Login', [
            'localLogin' => (bool) config('security.local_login', true),
            'oidc' => [
                'enabled' => $this->oidc->isConfigured(),
                'label' => $this->oidc->buttonLabel(),
            ],
            'setupRequired' => $this->setup->isSetupRequired(),
            'noMethods' => ! config('security.local_login', true) && ! $this->oidc->isConfigured(),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        abort_unless(config('security.local_login', true), 404);

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $email = strtolower(trim($credentials['email']));
        $ip = (string) $request->ip();

        if (($wait = $this->limiter->secondsUntilAllowed($email, $ip)) > 0) {
            Log::notice('Login blocked by rate limiter', ['ip' => $ip]);

            return back()->withErrors([
                'email' => 'Too many sign-in attempts. Please try again in '.$this->humanSeconds($wait).'.',
            ])->onlyInput('email');
        }

        $user = User::findByEmail($email);
        $hash = ($user && $user->hasLocalPassword()) ? $user->password : self::DUMMY_HASH;
        $passwordOk = Hash::check($credentials['password'], $hash);

        $valid = $user !== null
            && $user->hasLocalPassword()
            && $passwordOk
            && ! $user->isDisabled();

        if (! $valid) {
            $this->limiter->recordFailure($email, $ip);
            Log::notice('Failed login attempt', ['ip' => $ip]);

            return back()->withErrors(['email' => self::GENERIC_ERROR])->onlyInput('email');
        }

        $this->limiter->clear($email, $ip);

        $request->session()->regenerate();
        Auth::login($user, remember: false);
        $request->session()->put('auth.provider', 'local');

        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended('/');
    }

    /**
     * The sign-out button posts through Inertia (XHR). An XHR cannot follow a
     * redirect to another origin (the provider's end-session endpoint), so the
     * target is returned as an Inertia location response, which makes the
     * browser do a full page navigation. Leaving through a full page load also
     * drops any client-side state of the signed-out user.
     */
    public function logout(Request $request): SymfonyResponse
    {
        $provider = $request->session()->get('auth.provider');
        $idToken = $request->session()->get('oidc.id_token');

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($provider === 'oidc') {
            $endSession = $this->oidc->endSessionUrl(is_string($idToken) ? $idToken : null, route('login'));
            if ($endSession) {
                return Inertia::location($endSession);
            }
        }

        return Inertia::location(route('login'));
    }

    private function humanSeconds(int $seconds): string
    {
        if ($seconds >= 3600) {
            return ceil($seconds / 3600).' hour(s)';
        }
        if ($seconds >= 60) {
            return ceil($seconds / 60).' minute(s)';
        }

        return $seconds.' second(s)';
    }
}
