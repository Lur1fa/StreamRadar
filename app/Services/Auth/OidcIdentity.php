<?php

namespace App\Services\Auth;

final class OidcIdentity
{
    public function __construct(
        public readonly string $issuer,
        public readonly string $subject,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $name,
        /** @var array<string, mixed> All ID-token (and userinfo) claims. */
        public readonly array $claims,
        public readonly ?string $idToken,
    ) {}
}
