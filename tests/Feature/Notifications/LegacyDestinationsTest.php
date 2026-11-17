<?php

use App\Models\AlertRule;
use App\Models\NotificationDestination;
use App\Models\User;
use App\Services\Notifications\LegacyDestinations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/*
 * Until 2026-11-17 an account had one destination per channel, kept as
 * settings keys. The upgrade (and old backups) turn them into destinations.
 */

function oldAccountSettings(User $user, array $values): void
{
    foreach ($values as $key => $value) {
        DB::table('user_settings')->insert([
            'user_id' => $user->id,
            'key' => $key,
            'value' => in_array($key, LegacyDestinations::SECRET_KEYS, true) ? Crypt::encryptString($value) : $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

test('every account\'s old settings become its own destinations', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    oldAccountSettings($alice, [
        'mail_to' => 'alice@example.com',
        'notifications_email_enabled' => '0',
        'telegram_bot_token' => '123456:ABCDEFghijklmnopqrstuvwxyz',
        'telegram_chat_id' => '42',
        'webhook_url' => 'https://hooks.example.com/in?key=1',
        'webhook_format' => 'json',
        'theme' => 'dark',
    ]);
    oldAccountSettings($bob, ['discord_webhook_url' => 'https://discord.com/api/webhooks/2/bob']);

    expect(LegacyDestinations::convertAccountSettings())->toBe(4);

    $alices = NotificationDestination::ownedBy($alice)->orderBy('id')->get();
    expect($alices->map(fn ($d) => [$d->type->value, $d->enabled])->all())->toBe([['email', false], ['telegram', true], ['webhook', true]])
        ->and($alices[1]->secret('bot_token'))->toBe('123456:ABCDEFghijklmnopqrstuvwxyz')
        ->and($alices[1]->config['chat_id'])->toBe('42')
        ->and($alices[2]->secret('url'))->toBe('https://hooks.example.com/in?key=1')
        ->and($alices->every->isComplete())->toBeTrue();

    expect(NotificationDestination::ownedBy($bob)->sole()->secret('webhook_url'))->toBe('https://discord.com/api/webhooks/2/bob');

    // The old keys are gone; other settings stay.
    expect(DB::table('user_settings')->pluck('key')->all())->toBe(['theme']);
});

test('a webhook in ntfy format becomes an ntfy destination and its rules follow it', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $rule = AlertRule::factory()->for($alice, 'owner')->withWebhook()->create();
    $other = AlertRule::factory()->for($bob, 'owner')->withWebhook()->create();
    oldAccountSettings($alice, ['webhook_url' => 'https://ntfy.example.com/sub/path/alerts-1', 'webhook_format' => 'ntfy']);

    LegacyDestinations::convertAccountSettings();

    $ntfy = NotificationDestination::ownedBy($alice)->sole();
    expect($ntfy->type->value)->toBe('ntfy')
        ->and($ntfy->name)->toBe('ntfy · alerts-1')
        ->and($ntfy->config['server_url'])->toBe('https://ntfy.example.com/sub/path')
        ->and($ntfy->config['topic'])->toBe('alerts-1');

    expect($rule->refresh()->notify_ntfy)->toBeTrue()
        ->and($rule->notify_webhook)->toBeFalse()
        ->and($other->refresh()->notify_webhook)->toBeTrue()
        ->and($other->notify_ntfy)->toBeFalse();
});

test('an ntfy URL that is not a plain topic URL stays a webhook', function () {
    expect(LegacyDestinations::splitNtfyUrl('https://ntfy.example.com/topic?auth=abc'))->toBeNull()
        ->and(LegacyDestinations::splitNtfyUrl('https://ntfy.example.com/'))->toBeNull()
        ->and(LegacyDestinations::splitNtfyUrl('https://ntfy.example.com:8443/t_1'))->toBe(['server_url' => 'https://ntfy.example.com:8443', 'topic' => 't_1']);
});

test('a secret that cannot be decrypted is skipped, the rest is converted', function () {
    $alice = User::factory()->create();
    DB::table('user_settings')->insert([
        ['user_id' => $alice->id, 'key' => 'discord_webhook_url', 'value' => 'not-encrypted', 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $alice->id, 'key' => 'mail_to', 'value' => 'alice@example.com', 'created_at' => now(), 'updated_at' => now()],
    ]);

    expect(LegacyDestinations::convertAccountSettings())->toBe(1)
        ->and(NotificationDestination::ownedBy($alice)->sole()->type->value)->toBe('email');
});

test('a backup from before destinations existed imports its e-mail destination once', function () {
    $admin = User::factory()->admin()->create();
    $backup = ['format' => 2, 'settings' => ['theme' => 'dark', 'mail_to' => 'old@example.com', 'webhook_format' => 'json', 'notifications_email_enabled' => '0']];

    foreach ([1, 2] as $attempt) {
        $file = UploadedFile::fake()->createWithContent('backup.json', json_encode($backup));
        $this->actingAs($admin)->post('/settings/import', ['file' => $file])->assertSessionHas('success');
    }

    $destination = NotificationDestination::ownedBy($admin)->sole();
    expect($destination->recipients())->toBe(['old@example.com'])
        ->and($destination->enabled)->toBeFalse();
});

test('destinations survive an export and import, secrets excepted', function () {
    $admin = User::factory()->admin()->create();
    destination('ntfy', ['server_url' => 'https://ntfy.example.com', 'topic' => 'alerts', 'username' => '', 'title_prefix' => 'SR'], ['access_token' => 'tk_not_exported'], $admin);
    destination('webhook', ['method' => 'PUT', 'auth' => 'none', 'headers' => ['X-Key'], 'query' => []], ['url' => 'https://hooks.example.com/in', 'headers' => ['X-Key' => 'v']], $admin);

    $export = $this->actingAs($admin)->getJson('/settings/export')->json();
    expect(json_encode($export))->not->toContain('tk_not_exported')->not->toContain('hooks.example.com');

    // Into the same account: matched by name and type, stored secrets stay.
    $file = UploadedFile::fake()->createWithContent('backup.json', json_encode($export));
    $this->post('/settings/import', ['file' => $file])->assertSessionHas('success');
    expect(NotificationDestination::ownedBy($admin)->count())->toBe(2)
        ->and(NotificationDestination::ownedBy($admin)->where('type', 'ntfy')->sole()->secret('access_token'))->toBe('tk_not_exported');

    // Into another account: the webhook has no URL yet, so it is incomplete.
    $other = User::factory()->admin()->create();
    $file = UploadedFile::fake()->createWithContent('backup.json', json_encode($export));
    $this->actingAs($other)->post('/settings/import', ['file' => $file])->assertSessionHas('success');

    $imported = NotificationDestination::ownedBy($other)->orderBy('id')->get();
    expect($imported->map(fn ($d) => $d->type->value)->all())->toBe(['ntfy', 'webhook'])
        ->and($imported[0]->config['title_prefix'])->toBe('SR')
        ->and($imported[0]->isComplete())->toBeTrue()
        ->and($imported[1]->config['method'])->toBe('PUT')
        ->and($imported[1]->missing())->toBe(['url', 'headers']);
});

test('a destination in a backup goes through the same rules as the settings page', function () {
    $admin = User::factory()->admin()->create();
    $backup = ['destinations' => [['name' => 'Bad', 'type' => 'telegram', 'config' => ['chat_id' => 'abc']]]];

    $file = UploadedFile::fake()->createWithContent('backup.json', json_encode($backup));
    $this->actingAs($admin)->post('/settings/import', ['file' => $file])->assertSessionHas('error');

    expect(NotificationDestination::count())->toBe(0);
});
