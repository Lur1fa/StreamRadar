<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

final class Secrets
{
    /**
     * Decrypt a value encrypted with APP_KEY. Null when it cannot be
     * decrypted, typically because APP_KEY changed; the caller logs what
     * the user has to re-enter.
     */
    public static function tryDecrypt(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }
}
