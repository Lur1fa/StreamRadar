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
        ->where('templateFields.url.label', 'Twitch URL')
        ->where('templateFields.streamer_login.sample', 'caedrel')
        ->where('templateFields.viewers.sample', '55,210')
        ->where('templateDefaultMessage.0', ['type' => 'field', 'field' => 'title'])
    );
});

test('POST /alerts stores a notification template with click link, attachment and action buttons', function () {
    $this->post('/alerts', [
        'name' => 'With buttons',
        'notification_template' => [
            'version' => 2,
            'message' => [['type' => 'field', 'field' => 'streamer_name'], ['type' => 'text', 'value' => ' is live']],
            'click' => 'custom',
            'click_url' => [['type' => 'text', 'value' => 'https://example.com/'], ['type' => 'field', 'field' => 'streamer_login']],
            'attachment' => 'avatar',
            'actions' => [[
                'label' => [['type' => 'text', 'value' => 'Chat']],
                'url' => [['type' => 'text', 'value' => 'https://www.twitch.tv/popout/'], ['type' => 'field', 'field' => 'streamer_login'], ['type' => 'text', 'value' => '/chat']],
            ]],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(AlertRule::where('name', 'With buttons')->first()->notification_template)
        ->click->toBe('custom')
        ->attachment->toBe('avatar')
        ->actions->toHaveCount(1);
});

test('POST /alerts rejects an action button whose link is not http(s)', function () {
    $this->post('/alerts', [
        'name' => 'Bad button',
        'notification_template' => [
            'version' => 2,
            'actions' => [['label' => [['type' => 'text', 'value' => 'Run']], 'url' => [['type' => 'text', 'value' => 'javascript:alert(1)']]]],
        ],
    ])->assertSessionHasErrors('notification_template.actions.0.url');

    expect(AlertRule::where('name', 'Bad button')->exists())->toBeFalse();
});

test('spaces and line breaks around template text are kept', function () {
    $this->postJson('/alerts', [
        'name' => 'Spacing',
        'notification_template' => [
            'version' => 2,
            'message' => [['type' => 'field', 'field' => 'streamer_name'], ['type' => 'text', 'value' => " is live\n "], ['type' => 'field', 'field' => 'title']],
        ],
    ]);

    expect(AlertRule::where('name', 'Spacing')->first()->notification_template['message'][1]['value'])->toBe(" is live\n ");
});
