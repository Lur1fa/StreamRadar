<?php

namespace App\Http\Controllers;

use App\Models\AlertRule;
use App\Models\Category;
use App\Models\Setting;
use App\Models\TrackedChannel;
use App\Services\AlertService;
use App\Services\TwitchApiService;
use App\Services\AlertTemplateValidator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AlertController extends Controller
{
    public function index(): Response
    {
        $alertRules = AlertRule::with('category', 'latestTracking')
            ->orderByDesc('created_at')
            ->get();

        $categories = Category::orderBy('name')->get(['id', 'name', 'tags']);

        return Inertia::render('Alerts', [
            'alertRules' => $alertRules,
            'categories' => $categories,
            'emailConfigured' => ! empty(Setting::get('mail_to')) && ! empty(Setting::get('smtp_host')),
            'discordConfigured' => ! empty(Setting::get('discord_webhook_url')),
            'telegramConfigured' => ! empty(Setting::get('telegram_bot_token')) && ! empty(Setting::get('telegram_chat_id')),
            'webhookConfigured' => ! empty(Setting::get('webhook_url')),
        ]);
    }

    public function store(Request $request, AlertService $alertService, TwitchApiService $twitch): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'streamer_login' => 'nullable|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'category_ids' => 'nullable|array',
            'category_tags' => 'nullable|array',
            'min_viewers' => 'nullable|integer|min:0',
            'min_avg_viewers' => 'nullable|integer|min:0',
            'language' => 'nullable|string|max:10',
            'keywords' => 'nullable|array',
            'notify_email' => 'boolean',
            'notify_discord' => 'boolean',
            'notify_telegram' => 'boolean',
            'notify_webhook' => 'boolean',
            'notify_on_category_change' => 'boolean',
            'notify_on_stream_start' => 'boolean',
            'notification_template' => 'nullable|array',
        ]);

        $validated['match_mode'] = 'always';
        $validated['notification_template'] = app(AlertTemplateValidator::class)->validate($validated['notification_template'] ?? null);

        if (! empty($validated['streamer_login'])) {
            $validated['streamer_login'] = strtolower($validated['streamer_login']);
            $this->ensureChannelTracked($validated['streamer_login'], $twitch);
        }

        $rule = AlertRule::create($validated);

        // Seed tracking for existing streams so they don't spam on next sync
        $alertService->seedTrackingForRule($rule);

        return back()->with('success', 'Alert rule created.');
    }

    public function update(Request $request, AlertRule $alert): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
            'streamer_login' => 'nullable|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'category_ids' => 'nullable|array',
            'category_tags' => 'nullable|array',
            'min_viewers' => 'nullable|integer|min:0',
            'min_avg_viewers' => 'nullable|integer|min:0',
            'language' => 'nullable|string|max:10',
            'keywords' => 'nullable|array',
            'notify_email' => 'sometimes|boolean',
            'notify_discord' => 'sometimes|boolean',
            'notify_telegram' => 'sometimes|boolean',
            'notify_webhook' => 'sometimes|boolean',
            'notify_on_category_change' => 'sometimes|boolean',
            'notification_template' => 'sometimes|nullable|array',
        ]);

        if (! empty($validated['streamer_login'])) {
            $validated['streamer_login'] = strtolower($validated['streamer_login']);
            $this->ensureChannelTracked($validated['streamer_login'], app(TwitchApiService::class));
        }

        if (array_key_exists('notification_template', $validated)) {
            $validated['notification_template'] = app(AlertTemplateValidator::class)->validate($validated['notification_template']);
        }

        $alert->update($validated);

        return back()->with('success', 'Alert rule updated.');
    }

    private function ensureChannelTracked(string $login, TwitchApiService $twitch): void
    {
        if (TrackedChannel::where('user_login', $login)->exists()) {
            return;
        }

        $userId = $login;
        $userName = $login;
        $avatar = null;

        if ($twitch->isConfigured()) {
            try {
                $users = $twitch->getUsers([$login]);
                if (! empty($users)) {
                    $userId = $users[0]['id'];
                    $userName = $users[0]['display_name'] ?? $login;
                    $avatar = $users[0]['profile_image_url'] ?? null;
                }
            } catch (\Exception $e) {
                // Continue with what we have
            }
        }

        TrackedChannel::create([
            'twitch_user_id' => $userId,
            'user_login' => $login,
            'user_name' => $userName,
            'profile_image_url' => $avatar,
        ]);
    }

    public function destroy(AlertRule $alert): \Illuminate\Http\RedirectResponse
    {
        $alert->trackings()->delete();
        $alert->delete();

        return back()->with('success', 'Alert rule deleted.');
    }
}
