<?php

use App\Models\AlertRule;
use App\Models\BlacklistRule;
use App\Models\Category;
use App\Models\Setting;
use App\Models\TrackedChannel;
use App\Services\TwitchApiService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

test('GET /settings returns 200 with settings', function () {
    $this->get('/settings')
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->component('Settings')
            ->has('settings')
        );
});

test('GET /settings exposes only a mask and a flag for secrets', function () {
    Setting::set('twitch_client_secret', 'my_super_secret_key_123');

    $this->get('/settings')
        ->assertInertia(fn ($page) => $page
            ->where('settings.twitch_client_secret_set', true)
            ->where('settings.twitch_client_secret_masked', fn ($val) => str_ends_with($val, '_123') && ! str_contains($val, 'super'))
            ->missing('settings.twitch_client_secret')
        );
});

test('PUT /settings saves settings', function () {
    $this->put('/settings', ['theme' => 'dark'])->assertRedirect();

    expect(getAccountSetting('theme'))->toBe('dark');
});

test('PUT /settings normalises booleans and validates numbers', function () {
    $this->put('/settings', ['auto_sync_enabled' => false, 'sync_frequency_minutes' => 15])->assertRedirect()->assertSessionHasNoErrors();

    expect(Setting::get('auto_sync_enabled'))->toBe('0')
        ->and(Setting::get('sync_frequency_minutes'))->toBe('15');

    $this->from('/settings')->put('/settings', ['sync_frequency_minutes' => 0])->assertSessionHasErrors('sync_frequency_minutes');
    $this->from('/settings')->put('/settings', ['theme' => 'neon'])->assertSessionHasErrors('theme');
    $this->from('/settings')->put('/settings', ['smtp_port' => 'abc'])->assertSessionHasErrors('smtp_port');
});

test('PUT /settings does not overwrite a secret with its mask or an empty value', function () {
    Setting::set('twitch_client_secret', 'real_secret');

    $this->put('/settings', ['twitch_client_secret' => '••••••••cret']);
    expect(Setting::get('twitch_client_secret'))->toBe('real_secret');

    $this->put('/settings', ['twitch_client_secret' => '']);
    expect(Setting::get('twitch_client_secret'))->toBe('real_secret');

    $this->put('/settings', ['twitch_client_secret' => 'new_secret_value']);
    expect(Setting::get('twitch_client_secret'))->toBe('new_secret_value');
});

test('PUT /settings can explicitly clear a secret', function () {
    Setting::set('smtp_password', 'hunter2hunter2');

    $this->put('/settings', ['clear_secrets' => ['smtp_password']])->assertRedirect();

    expect(Setting::get('smtp_password'))->toBeNull();
});

test('PUT /settings ignores the alert destination keys of the old settings', function () {
    $this->put('/settings', ['mail_to' => 'old@example.com', 'webhook_url' => 'https://hooks.example.com/x'])->assertRedirect()->assertSessionHasNoErrors();

    expect(getAccountSetting('mail_to'))->toBeNull()
        ->and(getAccountSetting('webhook_url'))->toBeNull();
});

test('secrets are stored encrypted at rest', function () {
    Setting::set('smtp_password', 'hunter2hunter2');

    $raw = DB::table('settings')->where('key', 'smtp_password')->value('value');

    expect($raw)->not->toContain('hunter2')
        ->and(Setting::get('smtp_password'))->toBe('hunter2hunter2');
});

test('GET /settings/export returns JSON with data', function () {
    Category::factory()->create();
    TrackedChannel::factory()->create();
    BlacklistRule::factory()->create();
    setAccountSetting('theme', 'dark');

    $response = $this->get('/settings/export')->assertOk();
    $data = $response->json();

    expect($data)->toHaveKeys(['settings', 'categories', 'channels', 'blacklist', 'tag_filters', 'alerts', 'destinations'])
        ->and($data['settings']['theme'])->toBe('dark');
});

test('POST /settings/import imports from JSON file', function () {
    $exportData = [
        'settings' => ['theme' => 'light'],
        'categories' => [['twitch_id' => '123', 'name' => 'Test', 'is_active' => true, 'notifications_enabled' => true, 'use_global_filters' => true]],
        'channels' => [],
        'blacklist' => [],
    ];

    $file = UploadedFile::fake()->createWithContent('backup.json', json_encode($exportData));

    $this->post('/settings/import', ['file' => $file])->assertRedirect()->assertSessionHas('success');

    expect(getAccountSetting('theme'))->toBe('light');
    $this->assertDatabaseHas('categories', ['twitch_id' => '123']);
});

test('POST /settings/import round-trips an export including alert templates', function () {
    $category = Category::factory()->create(['twitch_id' => '777']);
    AlertRule::factory()->create([
        'name' => 'Round trip',
        'category_id' => $category->id,
        'notification_template' => ['version' => 1, 'blocks' => [['type' => 'field', 'field' => 'title']]],
    ]);

    $export = $this->get('/settings/export')->json();
    AlertRule::query()->delete();

    $file = UploadedFile::fake()->createWithContent('backup.json', json_encode($export));
    $this->post('/settings/import', ['file' => $file])->assertSessionHas('success');

    $rule = AlertRule::where('name', 'Round trip')->firstOrFail();
    expect($rule->category_id)->toBe($category->id)
        // A version 1 template comes back in the current format.
        ->and($rule->notification_template['version'])->toBe(2)
        ->and($rule->notification_template['message'][0]['field'])->toBe('title');
});

test('POST /settings/import rejects invalid JSON', function () {
    $file = UploadedFile::fake()->createWithContent('bad.json', 'not json');

    $this->post('/settings/import', ['file' => $file])->assertRedirect()->assertSessionHas('error');
});

test('POST /settings/test-twitch calls testConnection', function () {
    $this->mock(TwitchApiService::class, function ($mock) {
        $mock->shouldReceive('testConnection')->once()->andReturn(['success' => true, 'message' => 'Connection successful']);
    });

    $this->postJson('/settings/test-twitch')->assertOk()->assertJson(['success' => true]);
});
