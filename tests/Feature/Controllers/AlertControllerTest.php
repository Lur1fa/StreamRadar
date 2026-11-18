<?php

use App\Models\AlertRule;
use App\Models\Category;
use App\Models\Stream;
use App\Models\StreamAlertTracking;
use App\Models\TrackedChannel;
use App\Services\Notifications\DestinationType;
use App\Services\TwitchApiService;

test('GET /alerts returns 200 and lists alert rules', function () {
    AlertRule::factory()->count(3)->create();

    $this->get('/alerts')
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->component('Alerts')
            ->has('alertRules', 3)
        );
});

test('GET /alerts includes categories for form', function () {
    Category::factory()->count(2)->create();

    $this->get('/alerts')
        ->assertInertia(fn ($page) => $page
            ->has('categories', 2)
        );
});

test('POST /alerts creates a new alert rule', function () {
    $this->post('/alerts', [
        'name' => 'My Alert',
        'notify_email' => false,
        'notify_discord' => false,
        'notify_telegram' => false,
    ])->assertRedirect();

    $this->assertDatabaseHas('alert_rules', ['name' => 'My Alert', 'match_mode' => 'always']);
});

test('POST /alerts validates required fields', function () {
    $this->post('/alerts', [])->assertSessionHasErrors(['name']);
});

test('POST /alerts validates category_id exists', function () {
    $this->post('/alerts', [
        'name' => 'Test',
        'category_id' => 9999,
    ])->assertSessionHasErrors('category_id');
});

test('POST /alerts seeds tracking for existing streams', function () {
    $stream = Stream::factory()->create(['user_login' => 'target']);

    $this->post('/alerts', [
        'name' => 'Track Target',
        'streamer_login' => 'target',
        'notify_email' => false,
        'notify_discord' => false,
        'notify_telegram' => false,
    ])->assertRedirect();

    expect(StreamAlertTracking::count())->toBe(1);
});

test('POST /alerts with streamer_login auto-tracks the channel', function () {
    $this->mock(TwitchApiService::class, function ($mock) {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('getUsers')->andReturn([
            ['id' => '123', 'login' => 'shroud', 'display_name' => 'shroud', 'profile_image_url' => 'https://example.com/avatar.jpg'],
        ]);
    });

    $this->post('/alerts', [
        'name' => 'Shroud Alert',
        'match_mode' => 'always',
        'streamer_login' => 'Shroud',
        'notify_email' => false,
        'notify_discord' => false,
        'notify_telegram' => false,
    ])->assertRedirect();

    $this->assertDatabaseHas('tracked_channels', ['user_login' => 'shroud']);
    expect(AlertRule::first()->streamer_login)->toBe('shroud');
});

test('POST /alerts with streamer_login does not duplicate tracked channel', function () {
    TrackedChannel::factory()->create(['user_login' => 'shroud']);

    $this->post('/alerts', [
        'name' => 'Shroud Alert',
        'match_mode' => 'always',
        'streamer_login' => 'shroud',
        'notify_email' => false,
        'notify_discord' => false,
        'notify_telegram' => false,
    ])->assertRedirect();

    expect(TrackedChannel::where('user_login', 'shroud')->count())->toBe(1);
});

test('PUT /alerts/{id} updates an alert rule', function () {
    $rule = AlertRule::factory()->create(['name' => 'Old Name']);

    $this->put("/alerts/{$rule->id}", [
        'name' => 'New Name',
    ])->assertRedirect();

    expect($rule->fresh()->name)->toBe('New Name');
});

test('PUT /alerts/{id} can toggle is_active', function () {
    $rule = AlertRule::factory()->create(['is_active' => true]);

    $this->put("/alerts/{$rule->id}", ['is_active' => false])->assertRedirect();

    expect($rule->fresh()->is_active)->toBeFalse();
});

test('DELETE /alerts/{id} deletes rule and tracking', function () {
    $rule = AlertRule::factory()->create();
    StreamAlertTracking::factory()->create(['alert_rule_id' => $rule->id]);

    $this->delete("/alerts/{$rule->id}")->assertRedirect();

    $this->assertDatabaseMissing('alert_rules', ['id' => $rule->id]);
    expect(StreamAlertTracking::count())->toBe(0);
});

test('PUT /alerts/{id} saves every switch of the edit form, including "goes live"', function () {
    $rule = AlertRule::factory()->create(['notify_on_stream_start' => true, 'notify_on_category_change' => true, 'notify_ntfy' => false]);

    $this->put("/alerts/{$rule->id}", ['notify_on_stream_start' => false, 'notify_ntfy' => true])->assertRedirect();

    expect($rule->fresh())
        ->notify_on_stream_start->toBeFalse()
        ->notify_ntfy->toBeTrue()
        ->notify_on_category_change->toBeTrue();
});

test('GET /alerts ships the destination types and template fields from the server', function () {
    $this->get('/alerts')->assertInertia(fn ($page) => $page
        ->has('destinationTypes', count(DestinationType::cases()))
        ->where('destinationTypes.0.flag', 'notify_ntfy')
        ->where('templateFields.url', 'Twitch URL')
    );
});
