<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\OidcException;
use App\Services\Auth\OidcService;
use App\Support\Redactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class OidcController extends Controller
{
    public function __construct(private OidcService $oidc) {}

    public function redirect(Request $request): RedirectResponse
    {
        abort_unless($this->oidc->isConfigured(), 404);

        if (Auth::check()) {
            return redirect('/');
        }

        try {
            return redirect()->away($this->oidc->buildAuthorizationUrl($request));
        } catch (OidcException $e) {
            return redirect()->route('login')->withErrors(['oidc' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('OIDC redirect failed', ['error' => Redactor::message($e->getMessage())]);

            return redirect()->route('login')->withErrors(['oidc' => 'Single sign-on is currently unavailable.']);
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless($this->oidc->isConfigured(), 404);

        if (Auth::check()) {
            return redirect('/');
        }

        try {
            $identity = $this->oidc->handleCallback($request);
            $user = $this->oidc->resolveUser($identity);
        } catch (OidcException $e) {
            return redirect()->route('login')->withErrors(['oidc' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('OIDC callback failed', ['error' => Redactor::message($e->getMessage())]);

            return redirect()->route('login')->withErrors(['oidc' => 'Single sign-on is currently unavailable.']);
        }

        $request->session()->regenerate();
        Auth::login($user, remember: false);
        $request->session()->put('auth.provider', 'oidc');
        if ($identity->idToken) {
            $request->session()->put('oidc.id_token', $identity->idToken);
        }

        return redirect()->intended('/');
    }
}
