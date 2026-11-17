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
     * @param  string|null  $credentialsHint  Where credentials go instead of the URL (e.g. the access token field).
     */
    public function __construct(
        private array $allowedHosts = [],
        private bool $requireHttps = false,
        private ?string $pathPrefix = null,
        private ?string $credentialsHint = null,
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

        // A user name or password in the URL would end up in logs and history.
        $parts = parse_url(trim($value));
        if (is_array($parts) && (isset($parts['user']) || isset($parts['pass']))) {
            $fail(trim('Do not put a user name or password in the :attribute. '.($this->credentialsHint ?? '')));

            return;
        }

        try {
            app(OutboundUrlGuard::class)->validate($value, $this->allowedHosts, $this->requireHttps, $this->pathPrefix);
        } catch (UnsafeUrlException $e) {
            $message = 'The :attribute is not allowed: '.$e->getMessage();

            // A self-hosted target on the local network can be allowed by the operator.
            if ($e->allowable && $this->allowedHosts === []) {
                $message .= ' '.self::localNetworkHint();
            }

            $fail($message);
        }
    }

    /** How a server on the local network gets allowed, for whoever is signed in. */
    public static function localNetworkHint(): string
    {
        return auth()->user()?->isAdmin()
            ? 'To use a server on your own network, add its host name to WEBHOOK_ALLOWED_HOSTS and recreate the container.'
            : 'To use a server on your own network, ask an administrator to allow its host name.';
    }
}
