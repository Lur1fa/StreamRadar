<?php

namespace App\Services\Http;

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
 *  - Response size capped (Content-Length check + transfer abort).
 */
class SafeHttpClient
{
    public function __construct(private OutboundUrlGuard $guard) {}

    /**
     * @param  array{allowed_hosts?: string[], require_https?: bool, path_prefix?: ?string, content_type?: string}  $policy
     *
     * @throws UnsafeUrlException
     */
    public function post(string $url, array|string|null $body = null, array $headers = [], array $policy = []): Response
    {
        $validated = $this->guard->validate(
            $url,
            $policy['allowed_hosts'] ?? [],
            $policy['require_https'] ?? false,
            $policy['path_prefix'] ?? null,
        );

        $request = $this->pendingRequest($validated);

        if ($headers !== []) {
            $request = $request->withHeaders($headers);
        }

        if (is_string($body)) {
            return $request->withBody($body, $policy['content_type'] ?? 'text/plain')->post($validated->url);
        }

        return $request->post($validated->url, $body ?? []);
    }

    public function pendingRequest(ValidatedUrl $validated): PendingRequest
    {
        $max = max(1024, (int) config('security.webhook.max_response_bytes', 1048576));

        $curl = [
            CURLOPT_RESOLVE => self::resolveEntries($validated),
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($ch, $dlTotal, $dlNow) => $dlNow > $max ? 1 : 0,
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $curl[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_MAXFILESIZE_LARGE')) {
            $curl[CURLOPT_MAXFILESIZE_LARGE] = $max;
        } elseif (defined('CURLOPT_MAXFILESIZE')) {
            $curl[CURLOPT_MAXFILESIZE] = $max;
        }

        return Http::withoutRedirecting()
            ->connectTimeout((int) config('security.webhook.connect_timeout', 5))
            ->timeout((int) config('security.webhook.timeout', 10))
            ->withUserAgent('StreamRadar/1.0 (+https://github.com/stmn/StreamRadar)')
            ->withOptions([
                'curl' => $curl,
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
