<?php

namespace App\Services\Notifications;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A destination refused an alert (non-2xx answer). The message holds the
 * status and the server's own short reason, never the URL or credentials
 * StreamRadar sent; pass it through Redactor before showing it, in case the
 * server echoed something back.
 */
class DeliveryFailed extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    public static function fromResponse(string $service, Response $response): self
    {
        $detail = self::detail($response);

        return new self(
            "{$service} answered with HTTP {$response->status()}".($detail !== null ? " ({$detail})" : '').'.',
            $response->status(),
        );
    }

    /**
     * The server's own reason, such as ntfy's "forbidden" or Telegram's
     * "Bad Request: chat not found": the error field of a JSON answer, or a
     * short plain-text body. Never HTML pages.
     */
    private static function detail(Response $response): ?string
    {
        $json = $response->json();
        $text = null;

        if (is_array($json)) {
            foreach (['error', 'description', 'message'] as $key) {
                if (is_string($json[$key] ?? null) && trim($json[$key]) !== '') {
                    $text = $json[$key];
                    break;
                }
            }
        } elseif (! str_contains($response->body(), '<')) {
            $text = $response->body();
        }

        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $text));

        return $text === '' ? null : Str::limit($text, 160, '…');
    }
}
