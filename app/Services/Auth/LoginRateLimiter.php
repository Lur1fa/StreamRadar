<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Two layers of brute-force protection for the password login:
 *
 *  1. A per-client-IP cap across all accounts (sliding window).
 *  2. A per-account progressive lockout: after N failures the account is
 *     locked for a base period that doubles on every further failure, up to
 *     a maximum. The counter is keyed by the *submitted* identifier, so
 *     non-existent accounts are throttled exactly like real ones.
 */
class LoginRateLimiter
{
    public function secondsUntilAllowed(string $identifier, string $ip): int
    {
        $ipKey = $this->ipKey($ip);
        if (RateLimiter::tooManyAttempts($ipKey, $this->cfg('ip_max_attempts', 20))) {
            return max(1, RateLimiter::availableIn($ipKey));
        }

        $lockedUntil = (int) Cache::get($this->lockKey($identifier), 0);
        if ($lockedUntil > time()) {
            return $lockedUntil - time();
        }

        return 0;
    }

    public function recordFailure(string $identifier, string $ip): void
    {
        RateLimiter::hit($this->ipKey($ip), $this->cfg('ip_decay_seconds', 900));

        $attemptsKey = $this->attemptsKey($identifier);
        $ttl = $this->cfg('attempt_ttl_seconds', 86400);
        $attempts = (int) Cache::get($attemptsKey, 0) + 1;
        Cache::put($attemptsKey, $attempts, $ttl);

        $threshold = $this->cfg('lockout_threshold', 5);
        if ($attempts >= $threshold) {
            $base = $this->cfg('lockout_base_seconds', 60);
            $max = $this->cfg('lockout_max_seconds', 3600);
            $seconds = (int) min($max, $base * (2 ** min(20, $attempts - $threshold)));
            Cache::put($this->lockKey($identifier), time() + $seconds, $seconds + 5);
        }
    }

    public function clear(string $identifier, string $ip): void
    {
        RateLimiter::clear($this->ipKey($ip));
        Cache::forget($this->attemptsKey($identifier));
        Cache::forget($this->lockKey($identifier));
    }

    public function attempts(string $identifier): int
    {
        return (int) Cache::get($this->attemptsKey($identifier), 0);
    }

    private function ipKey(string $ip): string
    {
        return 'login:ip:'.sha1($ip);
    }

    private function attemptsKey(string $identifier): string
    {
        return 'login:attempts:'.sha1(strtolower(trim($identifier)));
    }

    private function lockKey(string $identifier): string
    {
        return 'login:lock:'.sha1(strtolower(trim($identifier)));
    }

    private function cfg(string $key, int $default): int
    {
        return (int) config("security.login.{$key}", $default);
    }
}
