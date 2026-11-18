<?php

namespace App\Services\Http;

use App\Support\Brand;
use App\Support\Url;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The only way the application contacts an operator-supplied URL.
 *
 *  - URL vetted by OutboundUrlGuard (scheme, host, resolved addresses).
 *  - DNS pinned to the vetted addresses (CURLOPT_RESOLVE) → no rebinding.
 *  - Redirects are never followed.
 *  - Only http/https protocols, short connect/read timeouts.
 *  - Response size capped (Content-Length check + transfer abort via the
 *    progress callback).
 */
class SafeHttpClient
{
    public function __construct(private OutboundUrlGuard $guard) {}

    public const METHODS = ['POST', 'PUT', 'PATCH'];

    /**
     * @param  array{allowed_hosts?: string[], require_https?: bool, path_prefix?: ?string, content_type?: string}  $policy
     *
     * @throws UnsafeUrlException
     */
    public function post(string $url, array|string|null $body = null, array $headers = [], array $policy = []): Response
    {
        return $this->send('POST', $url, $body, $headers, $policy);
    }

    /**
     * Send a request with a body. A string body is sent as is with
     * `content_type`; an array as JSON.
     *
     * Options, besides the policy of post(): `query` (name => value, added
     * to the URL before it is vetted), `basic_auth` ([user, password]) and
     * `bearer_token`.
     *
     * @param  array{allowed_hosts?: string[], require_https?: bool, path_prefix?: ?string, content_type?: string, query?: array<string, string>, basic_auth?: array{0: string, 1: string}, bearer_token?: string}  $options
     *
     * @throws UnsafeUrlException
     */
    public function send(string $method, string $url, array|string|null $body = null, array $headers = [], array $options = []): Response
    {
        $method = strtoupper($method);
        if (! in_array($method, self::METHODS, true)) {
            throw new UnsafeUrlException('The request method is not allowed.');
        }

        $url = Url::withQuery($url, $options['query'] ?? []);

        $validated = $this->guard->validate(
            $url,
            $options['allowed_hosts'] ?? [],
            $options['require_https'] ?? false,
            $options['path_prefix'] ?? null,
        );

        $request = $this->pendingRequest($validated);

        if ($headers !== []) {
            $request = $request->withHeaders($headers);
        }

        if (isset($options['basic_auth'])) {
            $request = $request->withBasicAuth($options['basic_auth'][0], $options['basic_auth'][1]);
        } elseif (isset($options['bearer_token'])) {
            $request = $request->withToken($options['bearer_token']);
        }

        if (is_string($body)) {
            return $request->withBody($body, $options['content_type'] ?? 'text/plain')->send($method, $validated->url);
        }

        return $request->asJson()->send($method, $validated->url, ['json' => $body ?? []]);
    }

    public function pendingRequest(ValidatedUrl $validated): PendingRequest
    {
        $max = max(1024, (int) config('security.webhook.max_response_bytes', 1048576));

        // Guzzle 8 only accepts raw cURL options from its allow-list; protocols
        // and progress have request options of their own.
        return Http::withoutRedirecting()
            ->connectTimeout((int) config('security.webhook.connect_timeout', 5))
            ->timeout((int) config('security.webhook.timeout', 10))
            ->withUserAgent(Brand::USER_AGENT)
            ->withOptions([
                'curl' => [CURLOPT_RESOLVE => self::resolveEntries($validated)],
                'protocols' => ['http', 'https'],
                // Returning true aborts the transfer: caps bodies without a Content-Length.
                'progress' => static fn ($downloadTotal, $downloaded) => $downloaded > $max,
                'on_headers' => static function ($response) use ($max): void {
                    $length = $response->getHeaderLine('Content-Length');
                    if ($length !== '' && (int) $length > $max) {
                        throw new RuntimeException('Response body exceeds the allowed size.');
                    }
                },
            ]);
    }

    /**
     * cURL "host:port:ip1,ip2" pin entries for the vetted addresses.
     *
     * @return string[]
     */
    public static function resolveEntries(ValidatedUrl $validated): array
    {
        return [sprintf('%s:%d:%s', $validated->host, $validated->port, implode(',', $validated->ips))];
    }
}
