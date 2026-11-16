<?php

namespace App\Rules;

use App\Services\Http\OutboundUrlGuard;
use App\Services\Http\UnsafeUrlException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeWebhookUrl implements ValidationRule
{
    /**
     * @param  string[]  $allowedHosts
     */
    public function __construct(
        private array $allowedHosts = [],
        private bool $requireHttps = false,
        private ?string $pathPrefix = null,
    ) {}

    public static function discord(): self
    {
        return new self((array) config('security.webhook.discord_hosts', []), true, '/api/webhooks/');
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a URL.');

            return;
        }

        try {
            app(OutboundUrlGuard::class)->validate($value, $this->allowedHosts, $this->requireHttps, $this->pathPrefix);
        } catch (UnsafeUrlException $e) {
            $fail('The :attribute is not allowed: '.$e->getMessage());
        }
    }
}
