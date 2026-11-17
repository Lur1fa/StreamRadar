<?php

use App\Models\User;
use App\Services\AccountDataService;
use App\Services\Notifications\LegacyDestinations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alert destinations become a list per account: any number of ntfy,
 * webhook, e-mail, Telegram and Discord destinations, each with a name and
 * its own credentials, instead of one settings key per channel.
 *
 * Every account's existing settings are turned into destinations, and data
 * that has no owner yet goes to the oldest administrator. A webhook
 * that used the ntfy format becomes an ntfy destination, and the alert
 * rules that sent to it now send to ntfy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('type', 20);
            $table->boolean('enabled')->default(true);
            $table->json('config')->nullable();
            $table->text('secrets')->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'type']);
        });

        Schema::table('alert_rules', function (Blueprint $table) {
            $table->boolean('notify_ntfy')->default(false)->after('notify_webhook');
        });

        $rows = DB::table('user_settings')->whereIn('key', LegacyDestinations::KEYS)->get()->groupBy('user_id');
        foreach ($rows as $userId => $settings) {
            $values = [];
            foreach ($settings as $row) {
                $values[$row->key] = LegacyDestinations::decode($row->key, $row->value);
            }
            LegacyDestinations::import((int) $userId, $values);
        }
        DB::table('user_settings')->whereIn('key', LegacyDestinations::KEYS)->delete();

        // Data and settings from the time everything was shared, not yet given
        // to an account (including what 2026_11_17_000001 left): the oldest
        // administrator gets them now, or the first one created later (see
        // User::booted()).
        $accounts = app(AccountDataService::class);
        $admin = User::query()->where('role', User::ROLE_ADMIN)->orderBy('created_at')->orderBy('id')->first();
        if ($admin && $accounts->hasOrphans()) {
            $accounts->adoptOrphans($admin);
        }

        foreach (LegacyDestinations::KEYS as $key) {
            Cache::forget("setting.{$key}");
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: several destinations of one type cannot
        // be represented by the old one-key-per-channel settings. Restore the
        // database backup to go back.
    }
};
