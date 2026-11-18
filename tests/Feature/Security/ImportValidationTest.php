<?php

use App\Models\AlertRule;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

function importFile(array $data)
{
    return UploadedFile::fake()->createWithContent('backup.json', json_encode($data));
}

test('an unknown setting key is rejected', function () {
    $this->post('/settings/import', ['file' => importFile(['settings' => ['evil_key' => 'x']])])
        ->assertRedirect()->assertSessionHas('error');

    expect(Setting::query()->where('key', 'evil_key')->exists())->toBeFalse();
});

test('an unknown top-level section is rejected', function () {
    $this->post('/settings/import', ['file' => importFile(['danger' => [1, 2, 3]])])
        ->assertRedirect()->assertSessionHas('error');
});

test('an invalid alert template is rejected and nothing is imported', function () {
    $payload = [
        'settings' => ['theme' => 'dark'],
        'alerts' => [[
            'name' => 'Bad', 'notification_template' => ['version' => 2, 'blocks' => 'nope'],
        ]],
    ];

    $this->post('/settings/import', ['file' => importFile($payload)])
        ->assertRedirect()->assertSessionHas('error');

    // Transactional: the valid setting in the same file must not have been applied.
    expect(getAccountSetting('theme'))->not->toBe('dark');
    expect(AlertRule::where('name', 'Bad')->exists())->toBeFalse();
});

test('a setting value that fails its own rule is rejected', function () {
    $this->post('/settings/import', ['file' => importFile(['settings' => ['sync_frequency_minutes' => 99999]])])
        ->assertRedirect()->assertSessionHas('error');
});

test('a webhook URL pointing at a private address is rejected on import', function () {
    $this->post('/settings/import', ['file' => importFile(['settings' => ['webhook_url' => 'http://169.254.169.254/latest/meta-data/']])])
        ->assertRedirect()->assertSessionHas('error');

    expect(getAccountSetting('webhook_url'))->toBeNull();
});

test('a valid import is applied', function () {
    $payload = [
        'settings' => ['theme' => 'light', 'sync_frequency_minutes' => 10],
        'alerts' => [[
            'name' => 'Good', 'notification_template' => ['version' => 1, 'blocks' => [['type' => 'field', 'field' => 'title']]],
        ]],
    ];

    $this->post('/settings/import', ['file' => importFile($payload)])
        ->assertRedirect()->assertSessionHas('success');

    expect(getAccountSetting('theme'))->toBe('light')
        ->and(AlertRule::where('name', 'Good')->exists())->toBeTrue();
});
