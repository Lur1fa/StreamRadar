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
use App\Services\Mail\SmtpSettings;
use App\Services\Notifications\DestinationType;
use App\Services\TwitchApiService;
use App\Support\Redactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Settings page serves two audiences. Every account reads and writes its
 * own settings (default filters, appearance) and manages where its alerts go
 * (NotificationDestinationController). The installation's settings (Twitch
 * API keys, scanning, SMTP server), the Twitch test and the backup are
 * administrator-only (see routes/web.php and UpdateSettingsRequest).
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
            'emailAvailable' => SmtpSettings::configured(),
            // Lists the page would otherwise keep its own copy of.
            'serverKeys' => Setting::SERVER_KEYS,
            'destinationTypes' => DestinationType::forBrowser(),
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

        if (array_intersect_key($data, array_flip(SmtpSettings::KEYS))) {
            SmtpSettings::apply();
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
        $twitchIds = Category::ownedBy($user)->pluck('twitch_id', 'id')->all();

        return response()->json([
            'format' => 3,
            'app' => 'StreamRadar',
            'exported_at' => now()->toIso8601String(),
            // Never includes secrets (Twitch secret, SMTP password, destination credentials).
            'settings' => [
                ...Setting::getMany(array_values(array_intersect($exportable, Setting::SERVER_KEYS))),
                ...UserSetting::getMany($user, array_values(array_intersect($exportable, Setting::ACCOUNT_KEYS))),
            ],
            'categories' => Category::ownedBy($user)->get(Category::EXPORT_COLUMNS)->toArray(),
            'channels' => TrackedChannel::ownedBy($user)->get(TrackedChannel::EXPORT_COLUMNS)->toArray(),
            'blacklist' => BlacklistRule::ownedBy($user)->get(BlacklistRule::EXPORT_COLUMNS)->toArray(),
            'tag_filters' => TagFilter::ownedBy($user)->get(TagFilter::EXPORT_COLUMNS)->toArray(),
            // Without their secrets: a restored destination may need its token or URL again.
            'destinations' => NotificationDestination::ownedBy($user)->orderBy('id')->get()->map(fn (NotificationDestination $d) => [
                'name' => $d->name,
                'type' => $d->type->value,
                'enabled' => $d->enabled,
                'config' => $d->publicConfig(),
            ])->toArray(),
            // Categories by Twitch id: database ids mean nothing in another installation.
            'alerts' => AlertRule::ownedBy($user)->get()->map(fn (AlertRule $rule) => [
                ...$rule->only(AlertRule::exportColumns()),
                'category_twitch_id' => $twitchIds[$rule->category_id] ?? null,
                'category_twitch_ids' => array_values(array_filter(array_map(fn ($id) => $twitchIds[$id] ?? null, $rule->category_ids ?? []))),
            ])->toArray(),
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
            if (($result['reason'] ?? null) !== 'not_configured') {
                $result['message'] = 'Connection failed. Check the client ID and secret.';
            }
        }

        return response()->json($result);
    }
}
