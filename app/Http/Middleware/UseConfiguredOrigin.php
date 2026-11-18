<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes APP_URL the public origin of the installation when it is an https://
 * address.
 *
 * Behind a TLS-terminating reverse proxy the container only ever sees plain
 * HTTP, so URLs derived from the incoming request come out as http://… unless
 * the proxy is listed in TRUSTED_PROXIES. That breaks everything that needs an
 * exact absolute URL: the OIDC redirect_uri, redirects followed by Inertia,
 * pagination links. With an https:// APP_URL the operator has already said
 * where the app lives, so that value is used instead of guessing:
 *
 *  - generated URLs (route(), url(), redirects, assets) use APP_URL;
 *  - the request itself reports APP_URL's scheme, host and port, so URLs
 *    taken from it (intended URL after login, pagination) match.
 *
 * It also means the Host header sent by the client is never reflected into a
 * URL. An http:// APP_URL (the default) changes nothing: the app then answers
 * on whatever address it was reached at, e.g. a LAN IP.
 *
 * TRUSTED_PROXIES is still what makes the real client IP visible.
 */
class UseConfiguredOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($origin = $this->origin()) {
            URL::useOrigin($origin['root']);
            URL::forceHttps();

            $request->headers->set('Host', $origin['authority']);
            $request->server->set('HTTP_HOST', $origin['authority']);
            $request->server->set('SERVER_PORT', (string) $origin['port']);
            $request->server->set('HTTPS', 'on');
        }

        return $next($request);
    }

    /**
     * @return array{root: string, authority: string, port: int}|null
     */
    private function origin(): ?array
    {
        $url = rtrim((string) config('app.url'), '/');
        $parts = parse_url($url);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return null;
        }

        $port = (int) ($parts['port'] ?? 443);

        return [
            'root' => $url,
            'authority' => $parts['host'].($port === 443 ? '' : ':'.$port),
            'port' => $port,
        ];
    }
}
