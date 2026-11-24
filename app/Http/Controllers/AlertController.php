<?php

namespace App\Http\Controllers;

use App\Models\AlertRule;
use App\Models\Category;
use App\Models\NotificationDestination;
use App\Models\TrackedChannel;
use App\Models\User;
use App\Services\AlertService;
use App\Services\AlertTemplateRenderer;
use App\Services\AlertTemplateValidator;
use App\Services\Mail\SmtpSettings;
use App\Services\Notifications\DestinationType;
use App\Services\Notifications\SampleAlerts;
use App\Services\TwitchUserLookup;
use Illuminate\Http\RedirectResponse;
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

        // Types the account can deliver to: an enabled, complete destination
        // (e-mail also needs the installation's mail server).
        $readyTypes = NotificationDestination::ownedBy($user)->where('enabled', true)->get()
            ->filter(fn (NotificationDestination $d) => $d->isComplete())
            ->map(fn (NotificationDestination $d) => $d->type)
            ->reject(fn (DestinationType $type) => $type === DestinationType::Email && ! SmtpSettings::configured())
            ->map(fn (DestinationType $type) => $type->value)
            ->unique()->values();

        return Inertia::render('Alerts', [
            'alertRules' => $alertRules,
            'categories' => $categories,
            'readyTypes' => $readyTypes,
            'destinationTypes' => DestinationType::forBrowser(),
            ...self::templateProps(),
        ]);
    }

    /**
     * What the notification editor needs: every template field with its
     * label, description and the value it has for a sample stream, and the
     * built-in message as blocks.
     *
     * @return array{templateFields: array<string, array{label: string, description: string, sample: string}>, templateDefaultMessage: array}
     */
    private static function templateProps(): array
    {
        $sample = SampleAlerts::preview();
        $values = app(AlertTemplateRenderer::class)->values($sample['stream'], ['rules' => [$sample['rule']->name]]);

        $fields = [];
        foreach (AlertTemplateValidator::fields() as $field => $info) {
            $fields[$field] = [...$info, 'sample' => $values[$field] ?? ''];
        }

        return [
            'templateFields' => $fields,
            'templateDefaultMessage' => AlertTemplateValidator::DEFAULT_MESSAGE,
        ];
    }

    public function store(Request $request, AlertService $alertService, TwitchUserLookup $lookup): RedirectResponse
    {
        $validated = $this->prepare($request, $request->validate($this->rules($request, creating: true)), $lookup);
        $validated['match_mode'] = 'always';

        $rule = AlertRule::createFor($request->user(), $validated);

        // Seed tracking for existing streams so they don't spam on next sync
        $alertService->seedTrackingForRule($rule);

        return back()->with('success', 'Alert rule created.');
    }

    public function update(Request $request, AlertRule $alert, TwitchUserLookup $lookup): RedirectResponse
    {
        $alert->update($this->prepare($request, $request->validate($this->rules($request, creating: false)), $lookup));

        return back()->with('success', 'Alert rule updated.');
    }

    public function destroy(AlertRule $alert): RedirectResponse
    {
        $alert->trackings()->delete();
        $alert->delete();

        return back()->with('success', 'Alert rule deleted.');
    }

    /**
     * One set of rules for creating and editing; when editing, a field that
     * is not sent stays as it is. A rule may only point at categories of the
     * same account.
     *
     * @return array<string, mixed>
     */
    private function rules(Request $request, bool $creating): array
    {
        $optional = $creating ? [] : ['sometimes'];

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'streamer_login' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('owner_id', $request->user()->id)],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer'],
            'category_tags' => ['nullable', 'array'],
            'min_viewers' => ['nullable', 'integer', 'min:0'],
            'min_avg_viewers' => ['nullable', 'integer', 'min:0'],
            'language' => ['nullable', 'string', 'max:10'],
            'keywords' => ['nullable', 'array'],
            ...array_fill_keys([...DestinationType::ruleFlags(), 'notify_on_category_change', 'notify_on_stream_start'], [...$optional, 'boolean']),
            'notification_template' => [...$optional, 'nullable', 'array'],
        ];
    }

    /**
     * Validated input as stored: own categories only, the streamer login
     * lower-cased and tracked, the template checked.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function prepare(Request $request, array $validated, TwitchUserLookup $lookup): array
    {
        // Ids of categories removed since the rule was written are dropped
        // instead of rejected, so an old rule stays editable.
        if (! empty($validated['category_ids'])) {
            $own = Category::ownedBy($request->user())->whereIn('id', $validated['category_ids'])->pluck('id')->all();
            $validated['category_ids'] = array_values(array_intersect(array_map('intval', $validated['category_ids']), $own));
        }

        if (! empty($validated['streamer_login'])) {
            $validated['streamer_login'] = strtolower($validated['streamer_login']);
            $this->ensureChannelTracked($request->user(), $validated['streamer_login'], $lookup);
        }

        if (array_key_exists('notification_template', $validated)) {
            $validated['notification_template'] = app(AlertTemplateValidator::class)->validate($validated['notification_template']);
        }

        return $validated;
    }

    private function ensureChannelTracked(User $owner, string $login, TwitchUserLookup $lookup): void
    {
        if (TrackedChannel::ownedBy($owner)->where('user_login', $login)->exists()) {
            return;
        }

        $user = $lookup->tryFind($login);
        $userId = $user['id'] ?? $login;

        if (TrackedChannel::ownedBy($owner)->where('twitch_user_id', $userId)->exists()) {
            return;
        }

        TrackedChannel::createFor($owner, [
            'twitch_user_id' => $userId,
            'user_login' => $login,
            'user_name' => $user['display_name'] ?? $login,
            'profile_image_url' => $user['profile_image_url'] ?? null,
        ]);
    }
}
