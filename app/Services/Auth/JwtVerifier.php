<?php

namespace App\Services\Auth;

use InvalidArgumentException;

/**
 * Minimal, dependency-free JWS verification for RSA (RS256/RS384/RS512)
 * signed ID tokens, with JWK → PEM conversion. Nothing here trusts the
 * token's own header for anything other than `alg` and `kid`; the allowed
 * algorithm list is enforced by the caller.
 */
class JwtVerifier
{
    private const ALGOS = [
        'RS256' => OPENSSL_ALGO_SHA256,
        'RS384' => OPENSSL_ALGO_SHA384,
        'RS512' => OPENSSL_ALGO_SHA512,
    ];

    /**
     * @return array{header: array, payload: array, signature: string, signing_input: string}
     */
    public function decode(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new InvalidArgumentException('Malformed JWT.');
        }

        [$h, $p, $s] = $parts;

        $header = json_decode(self::base64UrlDecode($h), true);
        $payload = json_decode(self::base64UrlDecode($p), true);
        $signature = self::base64UrlDecode($s);

        if (! is_array($header) || ! is_array($payload) || $signature === '') {
            throw new InvalidArgumentException('Malformed JWT.');
        }

        return [
            'header' => $header,
            'payload' => $payload,
            'signature' => $signature,
            'signing_input' => $h.'.'.$p,
        ];
    }

    public function verifyRsa(string $signingInput, string $signature, array $jwk, string $alg): bool
    {
        if (! isset(self::ALGOS[$alg])) {
            return false;
        }
        if (($jwk['kty'] ?? null) !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
            return false;
        }

        $pem = self::jwkToPem($jwk);
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            return false;
        }

        return openssl_verify($signingInput, $signature, $key, self::ALGOS[$alg]) === 1;
    }

    public static function supportedAlgorithms(): array
    {
        return array_keys(self::ALGOS);
    }

    public static function jwkToPem(array $jwk): string
    {
        $n = self::base64UrlDecode($jwk['n']);
        $e = self::base64UrlDecode($jwk['e']);

        $rsaPublicKey = self::der(0x30, self::derInteger($n).self::derInteger($e));
        $algorithmIdentifier = self::der(0x30, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $spki = self::der(0x30, $algorithmIdentifier.self::der(0x03, "\x00".$rsaPublicKey));

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    public static function base64UrlDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode($data, true);

        return $decoded === false ? '' : $decoded;
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function der(int $tag, string $content): string
    {
        return chr($tag).self::derLength(strlen($content)).$content;
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\0");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private static function derInteger(string $bin): string
    {
        $bin = ltrim($bin, "\0");
        if ($bin === '') {
            $bin = "\0";
        }
        if (ord($bin[0]) & 0x80) {
            $bin = "\0".$bin;
        }

        return self::der(0x02, $bin);
    }
}
