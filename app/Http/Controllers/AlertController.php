<?php

namespace App\Http\Controllers;

use App\Models\AlertRule;
use App\Models\Category;
use App\Models\Setting;
use App\Models\TrackedChannel;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\AlertService;
use App\Services\TwitchApiService;
use App\Services\AlertTemplateValidator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AlertController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $alertRules = AlertRule::ownedBy($user)
            ->with('category', 'latestTracking')
            ->orderByDesc('created_at')
            ->get();

        $categories = Category::ownedBy($user)->orderBy('name')->get(['id', 'name', 'tags']);

        // Destinations are the account's own; only the SMTP server is shared.
        return Inertia::render('Alerts', [
            'alertRules' => $alertRules,
            'categories' => $categories,
            'emailConfigured' => UserSetting::has($user, 'mail_to') && ! empty(Setting::get('smtp_host')),
            'discordConfigured' => UserSetting::has($user, 'discord_webhook_url'),
            'telegramConfigured' => UserSetting::has($user, 'telegram_bot_token') && UserSetting::has($user, 'telegram_chat_id'),
            'webhookConfigured' => UserSetting::has($user, 'webhook_url'),
        ]);
    }

    /**
     * A rule may only point at categories of the same account.
     *
     * @return array<string, mixed>
     */
    private function categoryRules(Request $request): array
    {
        return [
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('owner_id', $request->user()->id)],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer'],
        ];
    }

    /**
     * Keep only ids of categories the account still has. Ids of categories
     * removed since the rule was written are dropped instead of rejected, so
     * an old rule stays editable.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function withOwnCategories(Request $request, array $validated): array
    {
        if (! empty($validated['category_ids'])) {
            $own = Category::ownedBy($request->user())->whereIn('id', $validated['category_ids'])->pluck('id')->all();
            $validated['category_ids'] = array_values(array_intersect(array_map('intval', $validated['category_ids']), $own));
        }

        return $validated;
    }

    public function store(Request $request, AlertService $alertService, TwitchApiService $twitch): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'streamer_login' => 'nullable|string|max:255',
            ...$this->categoryRules($request),
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

        $validated = $this->withOwnCategories($request, $validated);
        $validated['match_mode'] = 'always';
        $validated['notification_template'] = app(AlertTemplateValidator::class)->validate($validated['notification_template'] ?? null);

        if (! empty($validated['streamer_login'])) {
            $validated['streamer_login'] = strtolower($validated['streamer_login']);
            $this->ensureChannelTracked($request->user(), $validated['streamer_login'], $twitch);
        }

        $rule = AlertRule::createFor($request->user(), $validated);

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
            ...$this->categoryRules($request),
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
            $this->ensureChannelTracked($request->user(), $validated['streamer_login'], app(TwitchApiService::class));
        }

        if (array_key_exists('notification_template', $validated)) {
            $validated['notification_template'] = app(AlertTemplateValidator::class)->validate($validated['notification_template']);
        }

        $alert->update($this->withOwnCategories($request, $validated));

        return back()->with('success', 'Alert rule updated.');
    }

    private function ensureChannelTracked(User $owner, string $login, TwitchApiService $twitch): void
    {
        if (TrackedChannel::ownedBy($owner)->where('user_login', $login)->exists()) {
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

        if (TrackedChannel::ownedBy($owner)->where('twitch_user_id', $userId)->exists()) {
            return;
        }

        TrackedChannel::createFor($owner, [
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
