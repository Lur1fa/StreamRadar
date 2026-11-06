<?php

namespace App\Http\Controllers;

use App\Models\AlertRule;
use App\Models\BlacklistRule;
use App\Models\Category;
use App\Models\Setting;
use App\Models\TagFilter;
use App\Models\TrackedChannel;
use App\Services\TwitchApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    private const SETTING_KEYS = [
        'twitch_client_id',
        'twitch_client_secret',
        'auto_sync_enabled',
        'sync_frequency_minutes',
        'global_min_viewers',
        'global_min_avg_viewers',
        'global_languages',
        'global_keywords',
        'theme',
        'mail_to',
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_encryption',
        'mail_from_address',
        'mail_from_name',
        'discord_webhook_url',
        'telegram_bot_token',
        'telegram_chat_id',
        'webhook_url',
        'notifications_email_enabled',
        'notifications_discord_enabled',
        'notifications_telegram_enabled',
        'notifications_webhook_enabled',
        'auth_username',
    ];

    public function index(): Response
    {
        $settings = Setting::getMany(self::SETTING_KEYS, [
            'sync_frequency_minutes' => '1',
            'global_min_viewers' => '0',
            'global_min_avg_viewers' => '0',
            'global_languages' => '[]',
            'global_keywords' => '[]',
            'theme' => 'system',
        ]);

        // Mask secrets
        if (! empty($settings['twitch_client_secret'])) {
            $settings['twitch_client_secret_masked'] = str_repeat('*', 8).substr($settings['twitch_client_secret'], -4);
        }
        if (! empty($settings['telegram_bot_token'])) {
            $settings['telegram_bot_token_masked'] = str_repeat('*', 8).substr($settings['telegram_bot_token'], -4);
        }

        $settings['auth_enabled'] = ! empty(Setting::get('auth_username')) && ! empty(Setting::get('auth_password'));

        return Inertia::render('Settings', [
            'settings' => $settings,
            'activeCategoriesCount' => Category::where('is_active', true)->count(),
        ]);
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = [];

        // Only include non-null values (ConvertEmptyStringsToNull turns '' into null)
        foreach (self::SETTING_KEYS as $key) {
            if ($request->has($key) && $request->input($key) !== null) {
                $data[$key] = $request->input($key);
            }
        }

        // Don't overwrite secrets with masked versions
        if (isset($data['twitch_client_secret']) && str_starts_with($data['twitch_client_secret'], '***')) {
            unset($data['twitch_client_secret']);
        }
        if (isset($data['telegram_bot_token']) && str_starts_with($data['telegram_bot_token'], '***')) {
            unset($data['telegram_bot_token']);
        }

        // Handle auth password separately (hash it)
        $authPassword = $request->input('auth_password');
        if ($authPassword) {
            Setting::set('auth_password', Hash::make($authPassword));
            // Keep current session authenticated so user isn't locked out
            $request->session()->put('authenticated', true);
        }

        Setting::setMany($data);

        // Update mail config dynamically if SMTP settings changed
        if (array_intersect_key($data, array_flip(['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'mail_from_address', 'mail_from_name']))) {
            $this->updateMailConfig();
        }

        return back()->with('success', 'Settings saved.');
    }

    public function disableAuth(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string']);

        $storedPassword = Setting::where('key', 'auth_password')->value('value');
        if (! $storedPassword || ! Hash::check($request->input('password'), $storedPassword)) {
            return response()->json(['message' => 'Invalid password'], 422);
        }

        Setting::where('key', 'auth_username')->delete();
        Setting::where('key', 'auth_password')->delete();
        \Illuminate\Support\Facades\Cache::forget('setting.auth_username');
        \Illuminate\Support\Facades\Cache::forget('setting.auth_password');

        return response()->json(['message' => 'Access control disabled']);
    }

    public function export(): JsonResponse
    {
        $settings = Setting::all()->pluck('value', 'key')->toArray();
        unset($settings['auth_password']); // Don't export hashed password

        return response()->json([
            'settings' => $settings,
            'categories' => Category::all(['twitch_id', 'name', 'box_art_url', 'is_active', 'notifications_enabled', 'use_global_filters', 'filter_source', 'min_viewers', 'min_avg_viewers', 'languages', 'keywords', 'tags'])->toArray(),
            'channels' => TrackedChannel::all(['twitch_user_id', 'user_login', 'user_name', 'profile_image_url', 'is_active'])->toArray(),
            'blacklist' => BlacklistRule::all(['type', 'value', 'twitch_user_id', 'profile_image_url'])->toArray(),
            'tag_filters' => TagFilter::all(['tag', 'min_viewers', 'min_avg_viewers', 'languages', 'keywords'])->toArray(),
            'alerts' => AlertRule::with('category')->get()->map(function ($rule) {
                $categoryMap = Category::whereIn('id', $rule->category_ids ?? [])->pluck('twitch_id')->toArray();

                return [
                    'name' => $rule->name,
                    'streamer_login' => $rule->streamer_login,
                    'category_twitch_id' => $rule->category?->twitch_id,
                    'category_twitch_ids' => $categoryMap,
                    'category_tags' => $rule->category_tags,
                    'match_mode' => $rule->match_mode,
                    'min_viewers' => $rule->min_viewers,
                    'min_avg_viewers' => $rule->min_avg_viewers,
                    'language' => $rule->language,
                    'keywords' => $rule->keywords,
                    'notify_email' => $rule->notify_email,
                    'notify_discord' => $rule->notify_discord,
                    'notify_telegram' => $rule->notify_telegram,
                    'notify_webhook' => $rule->notify_webhook,
                    'notify_on_category_change' => $rule->notify_on_category_change,
                    'notify_on_stream_start' => $rule->notify_on_stream_start,
                    'is_active' => $rule->is_active,
                ];
            })->toArray(),
        ]);
    }

    public function import(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate(['file' => 'required|file|mimes:json|max:1024']);

        $data = json_decode(file_get_contents($request->file('file')->getRealPath()), true);
        if (! $data) {
            return back()->with('error', 'Invalid JSON file.');
        }

        // Import settings (except auth)
        if (! empty($data['settings'])) {
            foreach ($data['settings'] as $key => $value) {
                if (in_array($key, ['auth_username', 'auth_password'])) {
                    continue;
                }
                Setting::set($key, $value);
            }
        }

        // Import categories
        if (! empty($data['categories'])) {
            foreach ($data['categories'] as $cat) {
                Category::updateOrCreate(
                    ['twitch_id' => $cat['twitch_id']],
                    collect($cat)->except('twitch_id')->toArray(),
                );
            }
        }

        // Import channels
        if (! empty($data['channels'])) {
            foreach ($data['channels'] as $ch) {
                TrackedChannel::updateOrCreate(
                    ['twitch_user_id' => $ch['twitch_user_id']],
                    collect($ch)->except('twitch_user_id')->toArray(),
                );
            }
        }

        // Import blacklist
        if (! empty($data['blacklist'])) {
            foreach ($data['blacklist'] as $rule) {
                BlacklistRule::updateOrCreate(
                    ['type' => $rule['type'], 'value' => $rule['value']],
                    collect($rule)->except(['type', 'value'])->toArray(),
                );
            }
        }

        // Import tag filters
        if (! empty($data['tag_filters'])) {
            foreach ($data['tag_filters'] as $tf) {
                TagFilter::updateOrCreate(
                    ['tag' => $tf['tag']],
                    collect($tf)->except('tag')->toArray(),
                );
            }
        }

        // Import alerts (map twitch_ids back to local category IDs)
        if (! empty($data['alerts'])) {
            $twitchToId = Category::pluck('id', 'twitch_id')->toArray();

            foreach ($data['alerts'] as $alert) {
                $alertData = collect($alert)->except(['name', 'category_twitch_id', 'category_twitch_ids', 'category_id', 'category_ids'])->toArray();

                // Map single category
                if (! empty($alert['category_twitch_id'])) {
                    $alertData['category_id'] = $twitchToId[$alert['category_twitch_id']] ?? null;
                }

                // Map multiple categories
                if (! empty($alert['category_twitch_ids'])) {
                    $alertData['category_ids'] = array_values(array_filter(
                        array_map(fn ($tid) => $twitchToId[$tid] ?? null, $alert['category_twitch_ids'])
                    ));
                }

                AlertRule::updateOrCreate(
                    ['name' => $alert['name']],
                    $alertData,
                );
            }
        }

        return back()->with('success', 'Configuration imported successfully.');
    }

    public function testTwitch(TwitchApiService $twitch): JsonResponse
    {
        return response()->json($twitch->testConnection());
    }

    public function testEmail(TwitchApiService $twitch): JsonResponse
    {
        $recipient = Setting::get('mail_to');
        if (! $recipient) {
            return response()->json(['success' => false, 'message' => 'No recipient email configured.']);
        }

        $this->updateMailConfig();

        try {
            $alerts = $this->buildTestAlerts($twitch);
            Mail::to($recipient)->send(new \App\Mail\StreamAlertMail($alerts));

            return response()->json(['success' => true, 'message' => "Test email sent to {$recipient}."]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function testDiscord(TwitchApiService $twitch): JsonResponse
    {
        $webhookUrl = Setting::get('discord_webhook_url');
        if (! $webhookUrl) {
            return response()->json(['success' => false, 'message' => 'No Discord webhook URL configured.']);
        }

        $fake = $this->pickTestStream($twitch);

        try {
            $response = Http::post($webhookUrl, [
                'embeds' => [[
                    'title' => "{$fake['user_name']} is live!",
                    'url' => "https://www.twitch.tv/{$fake['user_login']}",
                    'description' => $fake['title'],
                    'color' => 0x9147ff,
                    'fields' => [
                        ['name' => 'Category', 'value' => $fake['game'], 'inline' => true],
                        ['name' => 'Viewers', 'value' => number_format($fake['viewers']), 'inline' => true],
                        ['name' => 'Language', 'value' => strtoupper($fake['language']), 'inline' => true],
                    ],
                    'thumbnail' => ['url' => $fake['avatar']],
                    'footer' => ['text' => "Alert: {$fake['rule']} (test)"],
                    'timestamp' => now()->toIso8601String(),
                ]],
            ]);

            if ($response->successful()) {
                return response()->json(['success' => true, 'message' => 'Test message sent to Discord.']);
            }

            return response()->json(['success' => false, 'message' => "Discord returned HTTP {$response->status()}."]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function testTelegram(TwitchApiService $twitch): JsonResponse
    {
        $token = Setting::get('telegram_bot_token');
        $chatId = Setting::get('telegram_chat_id');
        if (! $token || ! $chatId) {
            return response()->json(['success' => false, 'message' => 'Telegram bot token or chat ID not configured.']);
        }

        $fake = $this->pickTestStream($twitch);
        $viewers = number_format($fake['viewers']);
        $url = "https://www.twitch.tv/{$fake['user_login']}";

        $text = "<b>🔴 {$fake['user_name']} is live!</b>\n"
            ."{$fake['title']}\n\n"
            ."🎮 {$fake['game']} · 👁 {$viewers} · 🌐 ".strtoupper($fake['language'])."\n"
            ."📋 Alert: {$fake['rule']} (test)\n\n"
            ."<a href=\"{$url}\">Watch on Twitch →</a>";

        try {
            $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => false,
            ]);

            if ($response->json('ok')) {
                return response()->json(['success' => true, 'message' => 'Test message sent to Telegram.']);
            }

            return response()->json(['success' => false, 'message' => $response->json('description', 'Unknown error')]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function testWebhook(TwitchApiService $twitch): JsonResponse
    {
        $webhookUrl = Setting::get('webhook_url');
        if (! $webhookUrl) {
            return response()->json(['success' => false, 'message' => 'No webhook URL configured.']);
        }

        $fake = $this->pickTestStream($twitch);

        $tags = implode(', ', ['English', $fake['game']]);

        $body = "{$fake['title']}\n"
            . "{$fake['game']} · ".number_format($fake['viewers'])." viewers\n"
            . "Tags: ".implode(', ', ['English', $fake['game']])."\n"
            . "Alert: {$fake['rule']} (test)";

        $headers = [
            'Title' => '=?UTF-8?B?'.base64_encode("{$fake['user_name']} is live").'?=',
            'Click' => "https://www.twitch.tv/{$fake['user_login']}",
        ];

        if ($fake['avatar']) {
            $headers['Icon'] = $fake['avatar'];
        }

        try {
            $response = Http::withHeaders($headers)
                ->withBody($body, 'text/plain')
                ->post($webhookUrl);

            return response()->json(['success' => true, 'message' => "Webhook responded with HTTP {$response->status()}."]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function buildTestAlerts(TwitchApiService $twitch): array
    {
        $fakeStreams = collect($this->testStreamTemplates())->random(2);
        $logins = $fakeStreams->pluck('user_login')->toArray();
        $avatarMap = $this->fetchAvatars($twitch, $logins);

        return $fakeStreams->map(function ($s) use ($avatarMap) {
            $stream = new \App\Models\Stream([
                'user_login' => $s['user_login'],
                'user_name' => $s['user_name'],
                'title' => $s['title'],
                'viewer_count' => $s['viewers'],
                'language' => $s['language'],
                'game_name' => $s['game'],
                'profile_image_url' => $avatarMap[$s['user_login']] ?? null,
                'started_at' => now()->subMinutes(rand(5, 120)),
            ]);

            return ['rule' => new \App\Models\AlertRule(['name' => $s['rule']]), 'stream' => $stream];
        })->toArray();
    }

    private function pickTestStream(TwitchApiService $twitch): array
    {
        $s = collect($this->testStreamTemplates())->random();
        $avatarMap = $this->fetchAvatars($twitch, [$s['user_login']]);
        $s['avatar'] = $avatarMap[$s['user_login']] ?? null;

        return $s;
    }

    private function fetchAvatars(TwitchApiService $twitch, array $logins): array
    {
        try {
            $users = $twitch->getUsers($logins);

            return collect($users)->mapWithKeys(fn ($u) => [
                $u['login'] => $u['profile_image_url'] ?? null,
            ])->toArray();
        } catch (\Exception) {
            return [];
        }
    }

    private function testStreamTemplates(): array
    {
        return [
            [
                'user_login' => 'shroud', 'user_name' => 'shroud',
                'title' => 'VALORANT Ranked Grind — Road to Radiant',
                'game' => 'VALORANT', 'viewers' => 42_831, 'language' => 'en',
                'rule' => 'FPS Streams',
            ],
            [
                'user_login' => 'pokimane', 'user_name' => 'pokimane',
                'title' => 'cozy morning stream — chatting & games',
                'game' => 'Just Chatting', 'viewers' => 28_445, 'language' => 'en',
                'rule' => 'Favorite Streamers',
            ],
            [
                'user_login' => 'caedrel', 'user_name' => 'Caedrel',
                'title' => 'LEC Watch Party — Playoffs Day 2',
                'game' => 'League of Legends', 'viewers' => 55_210, 'language' => 'en',
                'rule' => 'LoL Esports',
            ],
            [
                'user_login' => 'jynxzi', 'user_name' => 'Jynxzi',
                'title' => 'RAINBOW SIX SIEGE — Going for Diamond!!',
                'game' => 'Tom Clancy\'s Rainbow Six Siege', 'viewers' => 31_672, 'language' => 'en',
                'rule' => 'FPS Streams',
            ],
            [
                'user_login' => 'ibai', 'user_name' => 'ibai',
                'title' => 'LA VELADA DEL AÑO 4 — PREPARACION',
                'game' => 'Just Chatting', 'viewers' => 112_300, 'language' => 'es',
                'rule' => 'Big Spanish Streams',
            ],
        ];
    }

    public function checkUpdate(): JsonResponse
    {
        try {
            // Build-time version from VERSION file
            $versionFile = base_path('VERSION');
            $buildHash = file_exists($versionFile)
                ? trim(file_get_contents($versionFile))
                : trim(shell_exec('git -C '.base_path().' rev-parse HEAD 2>/dev/null') ?: '');

            if (! $buildHash || $buildHash === 'unknown') {
                return response()->json(['update_available' => false]);
            }

            // Current HEAD from mounted .git (after git pull on host)
            $latestHash = $this->resolveGitHead();

            if (! $latestHash) {
                return response()->json(['update_available' => false]);
            }

            $updateAvailable = $buildHash !== $latestHash;

            return response()->json([
                'update_available' => $updateAvailable,
                'local_version' => substr($buildHash, 0, 7),
                'remote_version' => $updateAvailable ? substr($latestHash, 0, 7) : null,
            ]);
        } catch (\Exception) {
            return response()->json(['update_available' => false]);
        }
    }

    private function resolveGitHead(): ?string
    {
        // Docker: mounted .git-head and .git-refs from host
        $headFile = base_path('.git-head');
        $refsDir = base_path('.git-refs');

        if (file_exists($headFile)) {
            $ref = trim(file_get_contents($headFile));
            if (str_starts_with($ref, 'ref: ')) {
                $refPath = $refsDir.'/'.str_replace('refs/', '', substr($ref, 5));

                return file_exists($refPath) ? trim(file_get_contents($refPath)) : null;
            }

            return $ref;
        }

        // Dev: direct git
        $hash = trim(shell_exec('git -C '.base_path().' rev-parse HEAD 2>/dev/null') ?: '');

        return $hash ?: null;
    }

    private function updateMailConfig(): void
    {
        $host = Setting::get('smtp_host');
        if (! $host) {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => Setting::get('smtp_port', 587),
            'mail.mailers.smtp.username' => Setting::get('smtp_username') ?: null,
            'mail.mailers.smtp.password' => Setting::get('smtp_password') ?: null,
            'mail.mailers.smtp.encryption' => Setting::get('smtp_encryption') ?: null,
            'mail.from.address' => Setting::get('mail_from_address'),
            'mail.from.name' => Setting::get('mail_from_name', 'StreamRadar'),
        ]);
    }
}
