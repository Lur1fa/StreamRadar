<?php

use App\Models\AlertRule;
use App\Models\BlacklistRule;
use App\Models\Category;
use App\Models\HistoryEvent;
use App\Models\NotificationDestination;
use App\Models\Stream;
use App\Models\StreamAlertTracking;
use App\Models\TagFilter;
use App\Models\TrackedChannel;
use App\Models\User;
use App\Models\UserSetting;

function giveData(User $account, string $twitchId = '777'): AlertRule
{
    $category = Category::factory()->for($account, 'owner')->create(['twitch_id' => $twitchId]);
    Stream::factory()->for($account, 'owner')->forCategory($category)->create(['twitch_id' => 's'.$twitchId]);
    TrackedChannel::factory()->for($account, 'owner')->create(['twitch_user_id' => 'c'.$twitchId]);
    BlacklistRule::factory()->for($account, 'owner')->create(['type' => 'keyword', 'value' => 'spoilers']);
    HistoryEvent::factory()->for($account, 'owner')->count(2)->create();
    TagFilter::createFor($account, ['tag' => 'retro']);
    $rule = AlertRule::factory()->for($account, 'owner')->create(['category_id' => $category->id, 'category_ids' => [$category->id]]);
    StreamAlertTracking::create(['alert_rule_id' => $rule->id, 'stream_twitch_id' => 's'.$twitchId, 'streamer_login' => 'x']);
    destination('webhook', ['method' => 'POST', 'auth' => 'none'], ['url' => 'https://hooks.example.com/'.$account->id], $account, name: 'Hook of '.$account->id);
    UserSetting::set($account, 'global_min_viewers', '50');

    return $rule;
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['email' => 'admin@example.com']);
    $this->daily = User::factory()->create(['email' => 'me@example.com']);
});

test('moves everything one account owns to another account', function () {
    $rule = giveData($this->admin);

    $this->artisan('user:move-data', ['from' => 'admin@example.com', 'to' => 'me@example.com', '--force' => true])
        ->assertSuccessful();

    foreach ([Category::class, Stream::class, TrackedChannel::class, BlacklistRule::class, TagFilter::class, AlertRule::class] as $model) {
        expect($model::ownedBy($this->daily)->count())->toBe(1, $model)
            ->and($model::ownedBy($this->admin)->count())->toBe(0, $model);
    }
    expect(HistoryEvent::ownedBy($this->daily)->count())->toBe(2);

    // The alert still points at its (moved) category and keeps its tracking.
    $moved = AlertRule::ownedBy($this->daily)->first();
    expect($moved->id)->toBe($rule->id)
        ->and($moved->category->owner_id)->toBe($this->daily->id)
        ->and($moved->trackings()->count())->toBe(1);

    // Alert destinations and filters move too, still decryptable.
    expect(NotificationDestination::ownedBy($this->daily)->sole()->secret('url'))->toBe('https://hooks.example.com/'.$this->admin->id)
        ->and(NotificationDestination::ownedBy($this->admin)->count())->toBe(0)
        ->and(UserSetting::get($this->daily, 'global_min_viewers'))->toBe('50')
        ->and(UserSetting::where('user_id', $this->admin->id)->count())->toBe(0);
});

test('refuses to move onto an account that has its own data', function () {
    giveData($this->admin, '777');
    giveData($this->daily, '888');

    $this->artisan('user:move-data', ['from' => 'admin@example.com', 'to' => 'me@example.com', '--force' => true])
        ->expectsOutputToContain('already has tracking data')
        ->assertFailed();

    expect(Category::ownedBy($this->admin)->count())->toBe(1)
        ->and(Category::ownedBy($this->daily)->value('twitch_id'))->toBe('888');
});

test('--replace deletes the target\'s data first', function () {
    giveData($this->admin, '777');
    giveData($this->daily, '777'); // same category and blacklist entry: would collide without --replace

    $this->artisan('user:move-data', ['from' => 'admin@example.com', 'to' => 'me@example.com', '--replace' => true, '--force' => true])
        ->assertSuccessful();

    expect(Category::ownedBy($this->daily)->count())->toBe(1)
        ->and(BlacklistRule::ownedBy($this->daily)->count())->toBe(1)
        ->and(AlertRule::ownedBy($this->daily)->count())->toBe(1)
        ->and(HistoryEvent::ownedBy($this->daily)->count())->toBe(2)
        ->and(StreamAlertTracking::count())->toBe(1)
        ->and(Category::ownedBy($this->admin)->count())->toBe(0)
        // Destinations are kept, like settings: the target ends up with both.
        ->and(NotificationDestination::ownedBy($this->daily)->orderBy('id')->get()->map(fn ($d) => $d->secret('url'))->all())
        ->toBe(['https://hooks.example.com/'.$this->admin->id, 'https://hooks.example.com/'.$this->daily->id]);
});

test('alert destinations alone do not stop a move', function () {
    giveData($this->admin);
    destination('email', ['to' => 'me@example.com'], [], $this->daily);

    $this->artisan('user:move-data', ['from' => 'admin@example.com', 'to' => 'me@example.com', '--force' => true])->assertSuccessful();

    expect(NotificationDestination::ownedBy($this->daily)->count())->toBe(2);
});

test('asks before moving and does nothing when declined', function () {
    giveData($this->admin);

    $this->artisan('user:move-data', ['from' => 'admin@example.com', 'to' => 'me@example.com'])
        ->expectsConfirmation('Continue?', 'no')
        ->assertSuccessful();

    expect(Category::ownedBy($this->admin)->count())->toBe(1)
        ->and(Category::ownedBy($this->daily)->count())->toBe(0);
});

test('rejects unknown accounts and moving an account onto itself', function () {
    $this->artisan('user:move-data', ['from' => 'nobody@example.com', 'to' => 'me@example.com', '--force' => true])->assertFailed();
    $this->artisan('user:move-data', ['from' => 'admin@example.com', 'to' => 'ADMIN@example.com', '--force' => true])->assertFailed();
});
