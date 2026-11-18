<?php

namespace App\Services\Http;

use RuntimeException;

class UnsafeUrlException extends RuntimeException
{
    /**
     * @param  bool  $allowable  True when listing the host in WEBHOOK_ALLOWED_HOSTS would let the URL through.
     */
    public function __construct(string $message, public readonly bool $allowable = false)
    {
        parent::__construct($message);
    }
}
