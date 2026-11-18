<?php

use App\Mail\StreamAlertMail;
use App\Models\NotificationDestination;
use App\Services\Http\OutboundUrlGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

function ntfyPayload(array $config = [], array $secrets = [], array $extra = []): array
{
    return [
        'name' => 'ntfy alerts',
        'type' => 'ntfy',
        'enabled' => true,
        'config' => ['server_url' => 'https://ntfy.example.com', 'topic' => 'alerts', 'username' => '', 'title_prefix' => '', ...$config],
        'secrets' => $secrets,
        ...$extra,
    ];
}

test('the settings page lists destinations with flags and masks, never the secrets', function () {
    destination('ntfy', ['server_url' => 'https://ntfy.example.com', 'topic' => 'alerts'], ['access_token' => 'tk_verysecret_token']);
    destination('webhook', ['method' => 'POST', 'auth' => 'none'], ['url' => 'https://hooks.example.com/secret-path-123']);

    $response = $this->get('/settings')->assertInertia(fn ($page) => $page
        ->has('destinations', 2)
        ->where('destinations.0.type', 'ntfy')
        ->where('destinations.0.config.topic', 'alerts')
        ->where('destinations.0.secrets_set.access_token', true)
        ->where('destinations.0.secrets_set.password', false)
        ->where('destinations.0.missing', [])
        ->where('destinations.1.secret_masks.url', fn ($mask) => str_starts_with($mask, 'https://hooks.example.com/') && ! str_contains($mask, 'secret-path'))
        ->missing('destinations.0.secrets'));

    expect($response->getContent())->not->toContain('tk_verysecret_token')->not->toContain('secret-path-123');
});

test('an ntfy destination is saved with its token encrypted at rest', function () {
    $this->post('/settings/destinations', ntfyPayload(['title_prefix' => 'StreamRadar'], ['access_token' => 'tk_abc123456']))
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

    $destination = NotificationDestination::sole();
    expect($destination->type->value)->toBe('ntfy')
        ->and($destination->config['title_prefix'])->toBe('StreamRadar')
        ->and($destination->secret('access_token'))->toBe('tk_abc123456')
        ->and(DB::table('notification_destinations')->value('secrets'))->not->toContain('tk_abc123456');
});

test('credentials in a URL are rejected with a pointer to the right fields', function () {
    $this->from('/settings')->post('/settings/destinations', ntfyPayload(['server_url' => 'https://phil:secret@ntfy.example.com']))
        ->assertSessionHasErrors(['config.server_url' => 'Do not put a user name or password in the server URL. Enter the access token, or the user name and password, in their own fields.']);

    $this->from('/settings')->post('/settings/destinations', [
        'name' => 'Hook', 'type' => 'webhook', 'enabled' => true,
        'config' => ['method' => 'POST', 'auth' => 'none'],
        'secrets' => ['url' => 'https://user:pw@hooks.example.com/in'],
    ])->assertSessionHasErrors(['secrets.url' => 'Do not put a user name or password in the URL. Choose Basic under Authentication instead.']);

    expect(NotificationDestination::count())->toBe(0);
});

test('an ntfy user name needs a password', function () {
    $this->from('/settings')->post('/settings/destinations', ntfyPayload(['username' => 'phil']))
        ->assertSessionHasErrors(['secrets.password' => 'Enter the password for this user name.']);

    $this->post('/settings/destinations', ntfyPayload(['username' => 'phil'], ['password' => 'pa55']))->assertSessionHasNoErrors();
});

test('editing keeps stored secrets unless replaced or removed', function () {
    $destination = destination('ntfy', ['server_url' => 'https://ntfy.example.com', 'topic' => 'alerts', 'username' => 'phil'], ['access_token' => 'tk_keep', 'password' => 'pw_keep']);

    $this->put("/settings/destinations/{$destination->id}", ntfyPayload(['topic' => 'renamed', 'username' => 'phil']))->assertSessionHasNoErrors();
    $destination->refresh();
    expect($destination->config['topic'])->toBe('renamed')
        ->and($destination->secret('access_token'))->toBe('tk_keep')
        ->and($destination->secret('password'))->toBe('pw_keep');

    $this->put("/settings/destinations/{$destination->id}", ntfyPayload(['username' => ''], ['access_token' => 'tk_new'], ['clear_secrets' => []]))->assertSessionHasNoErrors();
    $destination->refresh();
    expect($destination->secret('access_token'))->toBe('tk_new')
        ->and($destination->secret('password'))->toBeNull(); // no user name, no password

    $this->put("/settings/destinations/{$destination->id}", ntfyPayload([], [], ['clear_secrets' => ['access_token']]))->assertSessionHasNoErrors();
    expect($destination->refresh()->secret('access_token'))->toBeNull();
});

test('changing the type drops the old secrets and requires the new ones', function () {
    $destination = destination('discord', [], ['webhook_url' => 'https://discord.com/api/webhooks/1/token']);

    $this->from('/settings')->put("/settings/destinations/{$destination->id}", [
        'name' => 'Now Telegram', 'type' => 'telegram', 'enabled' => true, 'config' => ['chat_id' => '12345'], 'secrets' => [],
    ])->assertSessionHasErrors('secrets.bot_token');

    $this->put("/settings/destinations/{$destination->id}", [
        'name' => 'Now Telegram', 'type' => 'telegram', 'enabled' => true, 'config' => ['chat_id' => '12345'], 'secrets' => ['bot_token' => '123456:ABCDEFghijklmnopqrstuvwxyz'],
    ])->assertSessionHasNoErrors();

    $destination->refresh();
    expect($destination->type->value)->toBe('telegram')
        ->and($destination->secrets)->toBe(['bot_token' => '123456:ABCDEFghijklmnopqrstuvwxyz']);
});

test('webhook headers and query parameters keep their stored values by name', function () {
    $payload = fn (array $headerValues) => [
        'name' => 'Hook', 'type' => 'webhook', 'enabled' => true,
        'config' => ['method' => 'PUT', 'auth' => 'none', 'headers' => ['X-Api-Key', 'X-Source'], 'query' => ['token']],
        'secrets' => ['url' => 'https://hooks.example.com/in', 'headers' => $headerValues, 'query' => ['q-secret']],
    ];

    $this->from('/settings')->post('/settings/destinations', $payload(['key-1', '']))
        ->assertSessionHasErrors(['secrets.headers.1' => 'Enter a value.']);

    $this->post('/settings/destinations', $payload(['key-1', 'streamradar']))->assertSessionHasNoErrors();
    $destination = NotificationDestination::sole();

    // Empty values keep what is stored; the URL stays too.
    $this->put("/settings/destinations/{$destination->id}", [
        ...$payload(['', 'changed']),
        'secrets' => ['headers' => ['', 'changed'], 'query' => ['']],
    ])->assertSessionHasNoErrors();

    $destination->refresh();
    expect($destination->headers())->toBe(['X-Api-Key' => 'key-1', 'X-Source' => 'changed'])
        ->and($destination->queryParameters())->toBe(['token' => 'q-secret'])
        ->and($destination->secret('url'))->toBe('https://hooks.example.com/in')
        ->and($destination->isComplete())->toBeTrue();
});

test('a webhook cannot set headers the client or the authentication owns', function () {
    $base = ['name' => 'Hook', 'type' => 'webhook', 'enabled' => true, 'secrets' => ['url' => 'https://hooks.example.com/in', 'headers' => ['x'], 'auth_password' => 'pw']];

    $this->from('/settings')->post('/settings/destinations', [...$base, 'config' => ['method' => 'POST', 'auth' => 'none', 'headers' => ['Host']]])
        ->assertSessionHasErrors('config.headers.0');
    $this->from('/settings')->post('/settings/destinations', [...$base, 'config' => ['method' => 'POST', 'auth' => 'basic', 'auth_username' => 'u', 'headers' => ['Authorization']]])
        ->assertSessionHasErrors('config.headers.0');
    $this->from('/settings')->post('/settings/destinations', [...$base, 'config' => ['method' => 'DELETE', 'auth' => 'none']])
        ->assertSessionHasErrors('config.method');

    // With no authentication chosen, a custom Authorization header is fine.
    $this->post('/settings/destinations', [...$base, 'config' => ['method' => 'POST', 'auth' => 'none', 'headers' => ['Authorization']]])->assertSessionHasNoErrors();
});

test('e-mail, Telegram and Discord settings are validated', function () {
    $post = fn (string $type, array $config, array $secrets = []) => $this->from('/settings')->post('/settings/destinations', ['name' => 'x', 'type' => $type, 'enabled' => true, 'config' => $config, 'secrets' => $secrets]);

    $post('email', ['to' => 'ok@example.com, nope'])->assertSessionHasErrors(['config.to' => '"nope" is not a valid e-mail address.']);
    $post('email', ['to' => implode(',', array_map(fn ($i) => "a{$i}@example.com", range(1, 11)))])->assertSessionHasErrors('config.to');
    $post('telegram', ['chat_id' => 'abc'], ['bot_token' => '123456:ABCDEFghijklmnopqrstuvwxyz'])->assertSessionHasErrors('config.chat_id');
    $post('telegram', ['chat_id' => '1'], ['bot_token' => 'not a token'])->assertSessionHasErrors('secrets.bot_token');
    $post('discord', [], ['webhook_url' => 'https://example.com/not-discord'])->assertSessionHasErrors('secrets.webhook_url');
    $post('pigeon', [])->assertSessionHasErrors('type');

    $post('email', ['to' => 'one@example.com; two@example.com', 'subject_prefix' => '[Live]'])->assertSessionHasNoErrors();
    expect(NotificationDestination::sole()->recipients())->toBe(['one@example.com', 'two@example.com']);
});

test('a server on the local network is refused with a hint until it is allowed', function () {
    app()->instance(OutboundUrlGuard::class, new OutboundUrlGuard(fn () => ['192.168.1.20']));

    $this->from('/settings')->post('/settings/destinations', ntfyPayload(['server_url' => 'https://ntfy.home.example.net']))
        ->assertSessionHasErrors('config.server_url');
    expect(session('errors')->first('config.server_url'))->toContain('WEBHOOK_ALLOWED_HOSTS');

    config(['security.webhook.allowed_hosts' => ['ntfy.home.example.net']]);
    app()->instance(OutboundUrlGuard::class, new OutboundUrlGuard(fn () => ['192.168.1.20']));

    $this->post('/settings/destinations', ntfyPayload(['server_url' => 'https://ntfy.home.example.net']))->assertSessionHasNoErrors();
});

test('testing a Discord destination sends through the real delivery code', function () {
    Http::fake(['discord.com/*' => Http::response(null, 204)]);
    $destination = destination('discord', [], ['webhook_url' => 'https://discord.com/api/webhooks/123/token'], enabled: false);

    $this->postJson("/settings/destinations/{$destination->id}/test")->assertOk()->assertJson(['success' => true]);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://discord.com/api/webhooks/')
        && $request['allowed_mentions'] === ['parse' => []]
        && str_contains($request['embeds'][0]['description'], '(test)'));
});

test('testing an e-mail destination sends one mail', function () {
    Mail::fake();
    $destination = destination('email', ['to' => 'me@example.com']);

    $this->postJson("/settings/destinations/{$destination->id}/test")->assertOk()->assertJson(['success' => true]);

    Mail::assertSent(StreamAlertMail::class, fn (StreamAlertMail $mail) => $mail->hasTo('me@example.com') && count($mail->alerts) === 2);
});

test('a failed test says why without revealing the URL or token', function () {
    Http::fake(['*' => Http::response(['code' => 40301, 'http' => 403, 'error' => 'forbidden'], 403)]);
    $destination = destination('ntfy', ['server_url' => 'https://ntfy.example.com', 'topic' => 'secret-topic'], ['access_token' => 'tk_hidden_value']);

    $response = $this->postJson("/settings/destinations/{$destination->id}/test")->assertOk()
        ->assertJson(['success' => false, 'message' => 'ntfy answered with HTTP 403 (forbidden). Check the access token, or the user name and password.']);

    expect($response->getContent())->not->toContain('tk_hidden_value')->not->toContain('secret-topic');
});

test('an incomplete destination is not tested', function () {
    Http::fake();
    $destination = destination('discord');

    $this->postJson("/settings/destinations/{$destination->id}/test")->assertJson(['success' => false]);
    Http::assertNothingSent();
});

test('another account\'s destination cannot be seen, changed, tested or deleted', function () {
    $other = regularUser();
    $theirs = destination('discord', [], ['webhook_url' => 'https://discord.com/api/webhooks/9/theirs'], $other);

    $this->get('/settings')->assertInertia(fn ($page) => $page->has('destinations', 0));
    $this->put("/settings/destinations/{$theirs->id}", ['name' => 'x', 'type' => 'discord', 'enabled' => true, 'secrets' => ['webhook_url' => 'https://discord.com/api/webhooks/1/mine']])->assertNotFound();
    $this->postJson("/settings/destinations/{$theirs->id}/test")->assertNotFound();
    $this->delete("/settings/destinations/{$theirs->id}")->assertNotFound();

    expect($theirs->refresh()->secret('webhook_url'))->toBe('https://discord.com/api/webhooks/9/theirs');
});

test('a destination can be deleted', function () {
    $destination = destination('email', ['to' => 'me@example.com']);

    $this->delete("/settings/destinations/{$destination->id}")->assertRedirect()->assertSessionHas('success');

    expect(NotificationDestination::count())->toBe(0);
});

test('a failed test passes on the server\'s reason but never an HTML page', function () {
    $destination = destination('telegram', ['chat_id' => '42'], ['bot_token' => '123456:ABCDEFghijklmnopqrstuvwxyz']);

    Http::fake(['*' => Http::sequence()
        ->push(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'], 400)
        ->push('<html><body>502 Bad Gateway</body></html>', 502)]);

    $this->postJson("/settings/destinations/{$destination->id}/test")
        ->assertJson(['message' => 'Telegram answered with HTTP 400 (Bad Request: chat not found). Check the bot token and chat ID.']);
    $this->postJson("/settings/destinations/{$destination->id}/test")
        ->assertJson(['message' => 'Telegram answered with HTTP 502. Check the bot token and chat ID.']);
});

test('an unexpected error is named in the test result instead of a bare "failed"', function () {
    Http::fake(fn () => throw new RuntimeException('Something specific broke for https://ntfy.example.com/secret-topic'));
    $destination = destination('ntfy', ['server_url' => 'https://ntfy.example.com', 'topic' => 'secret-topic']);

    $message = $this->postJson("/settings/destinations/{$destination->id}/test")->json('message');

    expect($message)->toStartWith('Sending failed: RuntimeException: Something specific broke')
        ->not->toContain('secret-topic');
});
