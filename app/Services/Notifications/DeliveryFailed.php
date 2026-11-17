<?php

namespace App\Services\Notifications;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A destination refused an alert (non-2xx answer). The message never
 * contains the URL or credentials, so it may be shown to the account.
 */
class DeliveryFailed extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    public static function fromResponse(string $service, Response $response): self
    {
        return new self("{$service} answered with HTTP {$response->status()}.", $response->status());
    }
}
