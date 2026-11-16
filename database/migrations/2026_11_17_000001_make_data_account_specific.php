<?php

use App\Models\User;
use App\Services\AccountDataService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracking, alerts, blacklist, history and streams used to be shared by the
 * whole installation. Each of them now belongs to one account, and so do the
 * settings that say where that account's alerts are delivered.
 *
 * Existing data goes to the oldest administrator. Other accounts start
 * empty; `php artisan user:move-data` moves it to a different account. When
 * no account exists yet, the data stays without an owner until the first
 * administrator is created (see User::booted()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });

        foreach (AccountDataService::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            });
        }

        // What used to be unique for the installation is now unique per account.
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['twitch_id']);
            $table->unique(['owner_id', 'twitch_id']);
        });
        Schema::table('streams', function (Blueprint $table) {
            $table->dropUnique(['twitch_id']);
            $table->unique(['owner_id', 'twitch_id']);
            $table->index('twitch_id');
        });
        Schema::table('tracked_channels', function (Blueprint $table) {
            $table->dropUnique(['twitch_user_id']);
            $table->unique(['owner_id', 'twitch_user_id']);
        });
        Schema::table('tag_filters', function (Blueprint $table) {
            $table->dropUnique(['tag']);
            $table->unique(['owner_id', 'tag']);
        });
        Schema::table('blacklist_rules', function (Blueprint $table) {
            $table->dropUnique(['type', 'value']);
            $table->unique(['owner_id', 'type', 'value']);
        });
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->index('owner_id');
        });
        Schema::table('history_events', function (Blueprint $table) {
            $table->index(['owner_id', 'created_at']);
        });

        $admin = User::query()->where('role', User::ROLE_ADMIN)->orderBy('created_at')->orderBy('id')->first();

        if ($admin) {
            app(AccountDataService::class)->adoptOrphans($admin);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: several accounts may now track the same
        // category or channel, which the old installation-wide unique indexes
        // cannot represent. Restore the database backup to go back.
    }
};
