<?php

namespace App\Services\Http;

use Closure;

/**
 * Decides whether an operator-supplied URL may be contacted by the server.
 *
 * Blocks loopback, private, link-local, carrier-grade NAT, multicast,
 * reserved, documentation and IPv4-mapped/6to4/NAT64-embedded addresses, as
 * well as well-known internal hostnames (localhost, *.local, *.internal,
 * host.docker.internal, cloud metadata names, single-label names). The host
 * is resolved *here* and the vetted addresses are returned so the HTTP
 * client can pin them (see SafeHttpClient), which defeats DNS rebinding.
 *
 * Hosts listed in config('security.webhook.allowed_hosts') are exempt from
 * the address checks only — scheme and syntax rules still apply.
 */
class OutboundUrlGuard
{
    private const BLOCKED_V4 = [
        '0.0.0.0/8',        // "this" network
        '10.0.0.0/8',       // private
        '100.64.0.0/10',    // carrier-grade NAT (also Tailscale)
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local + cloud metadata
        '172.16.0.0/12',    // private (Docker default bridge lives here)
        '192.0.0.0/24',     // IETF protocol assignments
        '192.0.2.0/24',     // TEST-NET-1
        '192.88.99.0/24',   // 6to4 relay anycast (deprecated)
        '192.168.0.0/16',   // private
        '198.18.0.0/15',    // benchmarking
        '198.51.100.0/24',  // TEST-NET-2
        '203.0.113.0/24',   // TEST-NET-3
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved + broadcast
    ];

    private const BLOCKED_V6 = [
        '::/128',           // unspecified
        '::1/128',          // loopback
        '::ffff:0:0/96',    // IPv4-mapped (also unwrapped below)
        '::/96',            // IPv4-compatible (deprecated)
        '64:ff9b::/96',     // NAT64 (unwrapped below)
        '64:ff9b:1::/48',   // local-use NAT64
        '100::/64',         // discard-only
        '2001::/32',        // Teredo
        '2001:db8::/32',    // documentation
        '2002::/16',        // 6to4 (unwrapped below)
        'fc00::/7',         // unique local
        'fe80::/10',        // link-local
        'fec0::/10',        // site-local (deprecated)
        'ff00::/8',         // multicast
    ];

    private const BLOCKED_HOST_SUFFIXES = [
        '.localhost', '.local', '.internal', '.home.arpa', '.lan', '.intranet', '.corp', '.home', '.localdomain',
    ];

    private const BLOCKED_HOSTS = [
        'localhost', 'host.docker.internal', 'gateway.docker.internal', 'metadata.google.internal',
        'metadata', 'instance-data', 'kubernetes', 'kubernetes.default',
    ];

    /** @var Closure(string): string[] */
    private Closure $resolver;

    /** @var string[] */
    private array $exemptHosts;

    /**
     * @param  null|Closure(string): string[]  $resolver  Host → IP list. Defaults to system DNS.
     * @param  string[]|null  $exemptHosts  Overrides config('security.webhook.allowed_hosts').
     */
    public function __construct(?Closure $resolver = null, ?array $exemptHosts = null)
    {
        $this->resolver = $resolver ?? Closure::fromCallable([$this, 'systemResolve']);
        $this->exemptHosts = array_map('strtolower', $exemptHosts ?? (array) config('security.webhook.allowed_hosts', []));
    }

    /**
     * @param  string[]  $hostAllowlist  When non-empty, the host must be one of these (exact match).
     *
     * @throws UnsafeUrlException
     */
    public function validate(string $url, array $hostAllowlist = [], bool $requireHttps = false, ?string $pathPrefix = null): ValidatedUrl
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > 2048 || preg_match('/[\s\x00-\x1F\x7F]/', $url)) {
            throw new UnsafeUrlException('The URL is malformed.');
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new UnsafeUrlException('The URL is malformed.');
        }

        $scheme = strtolower($parts['scheme']);
        $allowedSchemes = $requireHttps ? ['https'] : ['http', 'https'];
        if (! in_array($scheme, $allowedSchemes, true)) {
            throw new UnsafeUrlException($requireHttps ? 'Only https:// URLs are allowed.' : 'Only http:// or https:// URLs are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrlException('Credentials embedded in the URL are not allowed.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $host = rtrim($host, '.');
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if ($port < 1 || $port > 65535) {
            throw new UnsafeUrlException('The URL port is invalid.');
        }

        if ($hostAllowlist !== [] && ! in_array($host, array_map('strtolower', $hostAllowlist), true)) {
            throw new UnsafeUrlException('This host is not permitted for this integration.');
        }

        if ($pathPrefix !== null && ! str_starts_with($parts['path'] ?? '/', $pathPrefix)) {
            throw new UnsafeUrlException('The URL path is not valid for this integration.');
        }

        $exempt = in_array($host, $this->exemptHosts, true);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } else {
            if (! $exempt && $this->isForbiddenHostname($host)) {
                throw new UnsafeUrlException('Internal or reserved hostnames are not allowed.', allowable: true);
            }
            if (! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                throw new UnsafeUrlException('The hostname is invalid.');
            }
            $ips = array_values(array_unique(array_filter(($this->resolver)($host), 'is_string')));
            if ($ips === []) {
                throw new UnsafeUrlException('The hostname could not be resolved.');
            }
        }

        if (! $exempt) {
            foreach ($ips as $ip) {
                if (! self::isPublicIp($ip)) {
                    throw new UnsafeUrlException('The URL resolves to a private, loopback or reserved address.', allowable: true);
                }
            }
        }

        return new ValidatedUrl($url, $scheme, $host, $port, $ips);
    }

    public function isForbiddenHostname(string $host): bool
    {
        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            return true;
        }

        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        // Single-label names only resolve inside a private DNS zone / search domain.
        if (! str_contains($host, '.')) {
            return true;
        }

        // Numeric disguises: decimal / octal / hex forms of an IPv4 address.
        if (preg_match('/^(0x[0-9a-f]+|\d+)$/i', $host) || preg_match('/^(0x[0-9a-f]+|\d+)(\.(0x[0-9a-f]+|\d+)){1,3}$/i', $host) && filter_var($host, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        return false;
    }

    public static function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return ! self::inAnyRange($packed, self::BLOCKED_V4);
        }

        // Unwrap embedded IPv4 (mapped, compatible, NAT64, 6to4) and check the v4 part too.
        $embedded = self::embeddedIpv4($packed);
        if ($embedded !== null && self::inAnyRange($embedded, self::BLOCKED_V4)) {
            return false;
        }

        return ! self::inAnyRange($packed, self::BLOCKED_V6);
    }

    private static function embeddedIpv4(string $packed16): ?string
    {
        $prefix12 = substr($packed16, 0, 12);

        if ($prefix12 === "\0\0\0\0\0\0\0\0\0\0\xff\xff"          // ::ffff:a.b.c.d
            || $prefix12 === str_repeat("\0", 12)                 // ::a.b.c.d
            || $prefix12 === "\x00\x64\xff\x9b\0\0\0\0\0\0\0\0") { // 64:ff9b::a.b.c.d
            return substr($packed16, 12, 4);
        }

        if (substr($packed16, 0, 2) === "\x20\x02") {              // 2002:AABB:CCDD::
            return substr($packed16, 2, 4);
        }

        return null;
    }

    private static function inAnyRange(string $packed, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $netPacked = inet_pton($net);
            if ($netPacked === false || strlen($netPacked) !== strlen($packed)) {
                continue;
            }
            if (self::prefixMatches($packed, $netPacked, (int) $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function prefixMatches(string $a, string $b, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;

        return ((ord($a[$bytes]) ^ ord($b[$bytes])) & $mask) === 0;
    }

    /** @return string[] */
    private function systemResolve(string $host): array
    {
        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }

        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (! empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }

        return $ips;
    }
}
