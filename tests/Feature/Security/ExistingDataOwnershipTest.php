<?php

use App\Models\Category;
use App\Models\NotificationDestination;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\AccountDataService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/*
 * An installation upgraded from the time everything was shared has rows
 * without an owner and keeps per-account settings in the shared table.
 * These tests put the database into that state and check who ends up with it.
 */

function legacyData(): void
{
    $now = now();
    DB::table('categories')->insert(['twitch_id' => '777', 'name' => 'Old Game', 'created_at' => $now, 'updated_at' => $now]);
    $categoryId = DB::table('categories')->where('twitch_id', '777')->value('id');
    DB::table('streams')->insert(['twitch_id' => 's1', 'user_id' => 'u1', 'user_login' => 'old', 'user_name' => 'Old', 'category_id' => $categoryId, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('tracked_channels')->insert(['twitch_user_id' => 'c1', 'user_login' => 'chan', 'user_name' => 'Chan', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('blacklist_rules')->insert(['type' => 'keyword', 'value' => 'spoilers', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('tag_filters')->insert(['tag' => 'retro', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('alert_rules')->insert(['name' => 'Old Alert', 'category_id' => $categoryId, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('history_events')->insert(['type' => 'stream_online', 'streamer_login' => 'old', 'created_at' => $now]);

    // Shared settings as the old version stored them (secrets already encrypted).
    DB::table('settings')->insert([
        ['key' => 'mail_to', 'value' => 'old@example.com', 'created_at' => $now, 'updated_at' => $now],
        ['key' => 'discord_webhook_url', 'value' => Crypt::encryptString('https://discord.com/api/webhooks/1/old'), 'created_at' => $now, 'updated_at' => $now],
        ['key' => 'global_min_viewers', 'value' => '42', 'created_at' => $now, 'updated_at' => $now],
        ['key' => 'twitch_client_id', 'value' => 'clientid', 'created_at' => $now, 'updated_at' => $now],
        ['key' => 'smtp_host', 'value' => 'smtp.example.com', 'created_at' => $now, 'updated_at' => $now],
    ]);
}

test('existing shared data and destinations are handed to one account, installation settings stay shared', function () {
    $first = User::factory()->admin()->create(['created_at' => now()->subYear()]);
    $second = User::factory()->admin()->create();
    $regular = User::factory()->create(['created_at' => now()->subYears(2)]);
    legacyData();

    // What the migration does: the oldest administrator, not the oldest account.
    $oldestAdmin = User::query()->where('role', User::ROLE_ADMIN)->orderBy('created_at')->orderBy('id')->first();
    expect($oldestAdmin->is($first))->toBeTrue();

    $counts = app(AccountDataService::class)->adoptOrphans($oldestAdmin);

    expect($counts)->toMatchArray(['categories' => 1, 'streams' => 1, 'tracked_channels' => 1, 'blacklist_rules' => 1, 'tag_filters' => 1, 'alert_rules' => 1, 'history_events' => 1, 'settings' => 1, 'notification_destinations' => 2]);

    foreach ([...AccountDataService::CONFIG_TABLES, ...AccountDataService::DERIVED_TABLES] as $table) {
        expect(DB::table($table)->where('owner_id', $first->id)->count())->toBe(1, $table)
            ->and(DB::table($table)->where('owner_id', '!=', $first->id)->orWhereNull('owner_id')->count())->toBe(0, $table);
    }

    // The old one-per-channel destinations became destinations of that account.
    $destinations = NotificationDestination::ownedBy($first)->orderBy('id')->get();
    expect($destinations->map(fn ($d) => $d->type->value)->all())->toBe(['email', 'discord'])
        ->and($destinations[0]->recipients())->toBe(['old@example.com'])
        ->and($destinations[1]->secret('webhook_url'))->toBe('https://discord.com/api/webhooks/1/old')
        ->and(NotificationDestination::where('owner_id', '!=', $first->id)->count())->toBe(0);

    expect(UserSetting::get($first, 'global_min_viewers'))->toBe('42')
        ->and(UserSetting::where('user_id', $second->id)->count())->toBe(0)
        ->and(UserSetting::where('user_id', $regular->id)->count())->toBe(0);

    expect(DB::table('settings')->pluck('key')->sort()->values()->all())->toBe(['smtp_host', 'twitch_client_id'])
        ->and(Setting::get('twitch_client_id'))->toBe('clientid');

    expect(app(AccountDataService::class)->hasOrphans())->toBeFalse();
});

test('the adopted data works: the account sees it and its filters apply', function () {
    $admin = User::factory()->admin()->create();
    legacyData();
    app(AccountDataService::class)->adoptOrphans($admin);

    $this->actingAs($admin)->get('/tracking')->assertInertia(fn ($page) => $page
        ->has('categories', 1)->where('categories.0.name', 'Old Game')
        ->where('globalFilters.min_viewers', 42));

    expect(Category::ownedBy($admin)->first()->effectiveFilters()['min_viewers'])->toBe(42);
});

test('with no account yet, the data waits for the first administrator', function () {
    legacyData();
    expect(app(AccountDataService::class)->hasOrphans())->toBeTrue();

    // A regular account does not take it over...
    $regular = User::factory()->create();
    expect(DB::table('categories')->whereNull('owner_id')->count())->toBe(1);

    // ...the first administrator does, however the account becomes one.
    $regular->forceFill(['role' => User::ROLE_ADMIN])->save();

    expect(DB::table('categories')->where('owner_id', $regular->id)->count())->toBe(1)
        ->and(NotificationDestination::ownedBy($regular)->where('type', 'email')->sole()->recipients())->toBe(['old@example.com'])
        ->and(app(AccountDataService::class)->hasOrphans())->toBeFalse();
});

test('a later administrator does not take over anybody\'s data', function () {
    $first = User::factory()->admin()->create();
    Category::factory()->for($first, 'owner')->create();
    destination('email', ['to' => 'first@example.com'], [], $first);

    $second = User::factory()->admin()->create();

    expect(Category::ownedBy($first)->count())->toBe(1)
        ->and(Category::ownedBy($second)->count())->toBe(0)
        ->and(NotificationDestination::ownedBy($second)->count())->toBe(0);
});
