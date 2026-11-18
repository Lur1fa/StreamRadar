<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Http\OutboundUrlGuard;
use App\Services\TwitchApiService;
use App\Support\RedactingEntriesRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\DevTools\EntriesRepository;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TwitchApiService::class);
        $this->app->singleton(OutboundUrlGuard::class, fn () => new OutboundUrlGuard);

        // Inertia DevTools: store entries only after pattern/value based redaction.
        $this->app->singleton(EntriesRepository::class, function () {
            $path = config('inertia.devtools.storage.path');

            return new RedactingEntriesRepository(
                path: is_string($path) ? $path : storage_path('inertia-devtools'),
                autoPruneHours: (int) config('inertia.devtools.storage.ttl', 24),
            );
        });
    }

    public function boot(): void
    {
        $this->enforceSecureRuntimeConfiguration();

        Gate::define('admin', fn (User $user) => $user->isAdmin() && ! $user->isDisabled());

        Password::defaults(fn () => Password::min((int) config('security.password_min_length', 12)));

        // Requests per minute. Before sign-in per client IP, afterwards per account.
        foreach (['login' => 30, 'setup' => 10, 'oidc' => 30] as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)->by((string) $request->ip()));
        }
        foreach (['sync' => 4, 'twitch-search' => 60, 'settings-tests' => 10] as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)->by((string) ($request->user()?->id ?: $request->ip())));
        }
    }

    /**
     * Fail safe on dangerous combinations rather than serving them:
     * debug output and DevTools are never active in production.
     */
    private function enforceSecureRuntimeConfiguration(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        if (config('app.debug')) {
            config(['app.debug' => false]);
            Log::warning('APP_DEBUG=true ignored because APP_ENV=production.');
        }

        if (config('inertia.devtools.enabled')) {
            config(['inertia.devtools.enabled' => false]);
            Log::warning('INERTIA_DEVTOOLS_ENABLED=true ignored because APP_ENV=production.');
        }
    }
}
