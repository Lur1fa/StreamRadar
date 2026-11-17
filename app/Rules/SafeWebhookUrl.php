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
            $message = 'The :attribute is not allowed: '.$e->getMessage();

            // A self-hosted target on the local network can be allowed by the operator.
            if ($e->allowable && $this->allowedHosts === []) {
                $message .= auth()->user()?->isAdmin()
                    ? ' To use a server on your own network, add its host name to WEBHOOK_ALLOWED_HOSTS and recreate the container.'
                    : ' To use a server on your own network, ask an administrator to allow its host name.';
            }

            $fail($message);
        }
    }
}
