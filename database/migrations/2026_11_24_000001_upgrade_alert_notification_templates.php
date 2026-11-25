<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Notification templates move from version 1 ({version: 1, blocks}) to
 * version 2, which adds the click action, attachment and action buttons.
 * The blocks become the message; everything else keeps today's behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('alert_rules')->whereNotNull('notification_template')->select('id', 'notification_template')->lazyById()
            ->each(function (object $rule) {
                $template = json_decode((string) $rule->notification_template, true);

                if (! is_array($template) || ($template['version'] ?? null) !== 1 || ! is_array($template['blocks'] ?? null)) {
                    return;
                }

                DB::table('alert_rules')->where('id', $rule->id)->update(['notification_template' => json_encode([
                    'version' => 2,
                    'message' => $template['blocks'] === [] ? null : array_values($template['blocks']),
                    'click' => 'stream',
                    'click_url' => null,
                    'attachment' => 'preview',
                    'actions' => [],
                ])]);
            });
    }

    public function down(): void
    {
        DB::table('alert_rules')->whereNotNull('notification_template')->select('id', 'notification_template')->lazyById()
            ->each(function (object $rule) {
                $template = json_decode((string) $rule->notification_template, true);

                if (is_array($template) && ($template['version'] ?? null) === 2) {
                    DB::table('alert_rules')->where('id', $rule->id)->update([
                        'notification_template' => is_array($template['message'] ?? null)
                            ? json_encode(['version' => 1, 'blocks' => $template['message']])
                            : null,
                    ]);
                }
            });
    }
};
