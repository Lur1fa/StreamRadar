<?php

namespace App\Services\Mail;

use App\Models\Setting;

/**
 * The installation's mail server (Settings → Mail Server), applied to
 * Laravel's mailer. Without a host there is no mail server and e-mail is
 * unavailable; other unset values fall back to Setting::DEFAULTS.
 */
final class SmtpSettings
{
    public const KEYS = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'mail_from_address', 'mail_from_name'];

    public static function configured(): bool
    {
        return filled(Setting::effective('smtp_host'));
    }

    public static function apply(): void
    {
        $host = Setting::effective('smtp_host');
        if (! $host) {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => Setting::effective('smtp_port'),
            'mail.mailers.smtp.username' => Setting::get('smtp_username') ?: null,
            'mail.mailers.smtp.password' => Setting::get('smtp_password') ?: null,
            'mail.mailers.smtp.encryption' => Setting::get('smtp_encryption') ?: null,
            // Without a stored sender, keep the one from the environment (MAIL_FROM_ADDRESS).
            'mail.from.address' => Setting::get('mail_from_address') ?: config('mail.from.address'),
            'mail.from.name' => Setting::effective('mail_from_name'),
        ]);
    }
}
