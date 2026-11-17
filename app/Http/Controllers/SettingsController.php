<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSettingsRequest;
use App\Models\AlertRule;
use App\Models\BlacklistRule;
use App\Models\Category;
use App\Models\NotificationDestination;
use App\Models\Setting;
use App\Models\TagFilter;
use App\Models\TrackedChannel;
use App\Models\UserSetting;
use App\Services\ConfigImportService;
use App\Services\TwitchApiService;
use App\Support\Redactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The Settings page serves two audiences. Every account reads and writes its
 * own settings (default filters, appearance) and manages where its alerts go
 * (NotificationDestinationController). The installation's settings (Twitch
 * API keys, scanning, SMTP server), the Twitch test, the update check and
 * the backup are administrator-only (see routes/web.php and
 * UpdateSettingsRequest).
 *
 * Secret handling: secrets never leave the server. The page receives only
 * `<key>_set` / `<key>_masked`; updates ignore empty or masked values; the
 * export omits every secret; test endpoints return generic results.
 */
class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $settings = UserSetting::publicView($user, Setting::ACCOUNT_KEYS, Setting::DEFAULTS);
        if ($user->isAdmin()) {
            $settings += Setting::publicView(Setting::SERVER_KEYS, Setting::DEFAULTS);
        }

        return Inertia::render('Settings', [
            'settings' => $settings,
            'destinations' => NotificationDestination::ownedBy($user)->orderBy('id')->get()
                ->map(fn (NotificationDestination $d) => $d->toBrowser())->values(),
            // Twitch is asked once per category, however many accounts track it.
            'activeCategoriesCount' => $user->isAdmin() ? Category::where('is_active', true)->distinct()->count('twitch_id') : 0,
            // Internal host names: shown in the administrators' Access section only.
            'webhookAllowedHosts' => $user->isAdmin() ? (array) config('security.webhook.allowed_hosts', []) : [],
            'emailAvailable' => ! empty(Setting::get('smtp_host')),
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        // The request has already dropped every key this account may not write.
        foreach ((array) $request->input('clear_secrets', []) as $key) {
            if (! Setting::isSecret($key)) {
                continue;
            }
            if (Setting::isAccountKey($key)) {
                UserSetting::forget($user, $key);
            } elseif ($user->isAdmin()) {
                Setting::forget($key);
            }
        }

        $data = UpdateSettingsRequest::normalize($validated);

        // Secrets: only overwrite when a new non-empty value was supplied.
        foreach (Setting::SECRET_KEYS as $key) {
            if (array_key_exists($key, $data) && ($data[$key] === null || $data[$key] === '')) {
                unset($data[$key]);
            }
        }

        Setting::storeFor($user, $data);

        if (array_intersect_key($data, array_flip(['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'mail_from_address', 'mail_from_name']))) {
            $this->updateMailConfig();
        }

        return back()->with('success', 'Settings saved.');
    }

    /**
     * Backup of the installation settings and of the signed-in
     * administrator's own account data.
     */
    public function export(Request $request): JsonResponse
    {
        $user = $request->user();
        $exportable = Setting::exportableKeys();

        return response()->json([
            'format' => 3,
            'app' => 'StreamRadar',
            'exported_at' => now()->toIso8601String(),
            // Never includes secrets (Twitch secret, SMTP password, destination credentials).
            'settings' => [
                ...Setting::getMany(array_values(array_intersect($exportable, Setting::SERVER_KEYS))),
                ...UserSetting::getMany($user, array_values(array_intersect($exportable, Setting::ACCOUNT_KEYS))),
            ],
            'categories' => Category::ownedBy($user)->get(['twitch_id', 'name', 'box_art_url', 'is_active', 'notifications_enabled', 'use_global_filters', 'filter_source', 'min_viewers', 'min_avg_viewers', 'languages', 'keywords', 'tags'])->toArray(),
            'channels' => TrackedChannel::ownedBy($user)->get(['twitch_user_id', 'user_login', 'user_name', 'profile_image_url', 'is_active'])->toArray(),
            'blacklist' => BlacklistRule::ownedBy($user)->get(['type', 'value', 'twitch_user_id', 'profile_image_url'])->toArray(),
            'tag_filters' => TagFilter::ownedBy($user)->get(['tag', 'min_viewers', 'min_avg_viewers', 'languages', 'keywords'])->toArray(),
            // Without their secrets: a restored destination may need its token or URL again.
            'destinations' => NotificationDestination::ownedBy($user)->orderBy('id')->get()->map(fn (NotificationDestination $d) => [
                'name' => $d->name,
                'type' => $d->type->value,
                'enabled' => $d->enabled,
                'config' => $d->publicConfig(),
            ])->toArray(),
            'alerts' => AlertRule::ownedBy($user)->with('category')->get()->map(function ($rule) use ($user) {
                $categoryMap = Category::ownedBy($user)->whereIn('id', $rule->category_ids ?? [])->pluck('twitch_id')->toArray();

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
                    'notify_ntfy' => $rule->notify_ntfy,
                    'notify_on_category_change' => $rule->notify_on_category_change,
                    'notify_on_stream_start' => $rule->notify_on_stream_start,
                    'notification_template' => $rule->notification_template,
                    'is_active' => $rule->is_active,
                ];
            })->toArray(),
        ]);
    }

    public function import(Request $request, ConfigImportService $importer): RedirectResponse
    {
        $request->validate(['file' => 'required|file|mimes:json|max:1024']);

        $raw = file_get_contents($request->file('file')->getRealPath());
        $data = json_decode((string) $raw, true, 32);

        if (! is_array($data)) {
            return back()->with('error', 'Invalid JSON file.');
        }

        try {
            $counts = $importer->import($data, $request->user());
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();

            return back()->with('error', 'Import rejected: '.$first);
        }

        $summary = collect($counts)->filter()->map(fn ($n, $k) => "{$n} ".str_replace('_', ' ', $k))->implode(', ');

        return back()->with('success', 'Configuration imported successfully'.($summary ? " ({$summary})." : '.'));
    }

    // ── Test endpoints (generic results only; details go to the log, scrubbed) ──

    public function testTwitch(TwitchApiService $twitch): JsonResponse
    {
        $result = $twitch->testConnection();

        if (! ($result['success'] ?? false)) {
            Log::info('Twitch connection test failed', ['error' => Redactor::message($result['message'] ?? '')]);
            $result['message'] = $result['message'] === 'Twitch API credentials not configured'
                ? $result['message']
                : 'Connection failed. Check the client ID and secret.';
        }

        return response()->json($result);
    }

    public function checkUpdate(): JsonResponse
    {
        try {
            $buildHash = $this->readFirstLine(base_path('VERSION'));

            if (! $buildHash || $buildHash === 'unknown') {
                return response()->json(['update_available' => false]);
            }

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
        } catch (Throwable) {
            return response()->json(['update_available' => false]);
        }
    }

    /**
     * Resolve the checked-out commit from the mounted git metadata without
     * spawning a shell. Docker mounts .git-head / .git-refs; a source checkout
     * has .git/HEAD directly.
     */
    private function resolveGitHead(): ?string
    {
        foreach ([[base_path('.git-head'), base_path('.git-refs')], [base_path('.git/HEAD'), base_path('.git/refs')]] as [$headFile, $refsDir]) {
            if (! is_file($headFile)) {
                continue;
            }

            $ref = $this->readFirstLine($headFile);
            if ($ref === null) {
                return null;
            }

            if (str_starts_with($ref, 'ref: ')) {
                $relative = substr($ref, 5);
                $relative = str_starts_with($relative, 'refs/') ? substr($relative, 5) : $relative;
                if (! preg_match('#^[A-Za-z0-9._/\-]+$#', $relative) || str_contains($relative, '..')) {
                    return null;
                }
                $hash = $this->readFirstLine($refsDir.'/'.$relative);

                return $hash && preg_match('/^[0-9a-f]{40}$/', $hash) ? $hash : null;
            }

            return preg_match('/^[0-9a-f]{40}$/', $ref) ? $ref : null;
        }

        return null;
    }

    private function readFirstLine(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $line = trim((string) strtok((string) file_get_contents($path, false, null, 0, 512), "\n"));

        return $line === '' ? null : $line;
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
