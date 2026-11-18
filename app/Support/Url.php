<?php

namespace App\Support;

final class Url
{
    /**
     * Append query parameters to a URL that may already have a query string.
     * RFC 3986 encoding (spaces as %20), as OAuth/OIDC endpoints expect.
     *
     * Replaces the `str_contains($url, '?') ? '&' : '?'` idiom in
     * OidcService (authorization and end-session URLs) and SafeHttpClient.
     *
     * @param  array<string, mixed>  $params
     */
    public static function withQuery(string $url, array $params): string
    {
        if ($params === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
