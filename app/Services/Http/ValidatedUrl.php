<?php

namespace App\Services\Http;

final class ValidatedUrl
{
    /**
     * @param  string[]  $ips  Every address the host resolved to (all vetted).
     */
    public function __construct(
        public readonly string $url,
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly array $ips,
    ) {}
}
