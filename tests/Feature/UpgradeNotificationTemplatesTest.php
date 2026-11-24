<?php

use App\Models\AlertRule;
use Illuminate\Support\Facades\DB;

test('the upgrade migration turns version 1 templates into version 2 and back', function () {
    $migration = require database_path('migrations/2026_11_24_000001_upgrade_alert_notification_templates.php');
    $legacy = AlertRule::factory()->create();
    $empty = AlertRule::factory()->create();
    $broken = AlertRule::factory()->create();
    DB::table('alert_rules')->where('id', $legacy->id)->update(['notification_template' => json_encode(['version' => 1, 'blocks' => [['type' => 'field', 'field' => 'title']]])]);
    DB::table('alert_rules')->where('id', $empty->id)->update(['notification_template' => json_encode(['version' => 1, 'blocks' => []])]);
    DB::table('alert_rules')->where('id', $broken->id)->update(['notification_template' => json_encode(['version' => 1, 'blocks' => 'nope'])]);

    $migration->up();

    expect($legacy->fresh()->notification_template)->toBe([
        'version' => 2,
        'message' => [['type' => 'field', 'field' => 'title']],
        'click' => 'stream',
        'click_url' => null,
        'attachment' => 'preview',
        'actions' => [],
    ])
        ->and($empty->fresh()->notification_template['message'])->toBeNull()
        // Left alone; the renderer falls back to the built-in message.
        ->and($broken->fresh()->notification_template)->toBe(['version' => 1, 'blocks' => 'nope']);

    $migration->down();

    expect($legacy->fresh()->notification_template)->toBe(['version' => 1, 'blocks' => [['type' => 'field', 'field' => 'title']]])
        ->and($empty->fresh()->notification_template)->toBeNull();
});
