<?php

namespace App\Services\Auth;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * First-administrator bootstrap without any default credentials.
 *
 * While the installation has no user accounts, an operator who can run
 * `php artisan auth:setup-token` (or read the container log, where the
 * Docker entrypoint prints it) obtains a random one-time token. Presenting
 * that token on /setup lets them create the first admin account. The token
 * is stored only as a SHA-256 hash, expires, and is consumed on use.
 */
class SetupTokenService
{
    public function isSetupRequired(): bool
    {
        return User::query()->count() === 0;
    }

    public function issue(): string
    {
        $token = Str::random(48);

        Setting::set('setup_token_hash', hash('sha256', $token));
        Setting::set('setup_token_expires_at', now()->addMinutes($this->ttlMinutes())->toIso8601String());

        return $token;
    }

    public function hasActiveToken(): bool
    {
        if (! Setting::has('setup_token_hash')) {
            return false;
        }

        $expires = Setting::get('setup_token_expires_at');

        return $expires && Carbon::parse($expires)->isFuture();
    }

    public function verify(?string $token): bool
    {
        if (! $this->isSetupRequired() || ! $this->hasActiveToken() || ! is_string($token) || $token === '') {
            return false;
        }

        return hash_equals((string) Setting::get('setup_token_hash'), hash('sha256', $token));
    }

    public function consume(): void
    {
        Setting::forget('setup_token_hash');
        Setting::forget('setup_token_expires_at');
    }

    public function ttlMinutes(): int
    {
        return max(1, (int) config('security.setup_token_ttl_minutes', 60));
    }
}
