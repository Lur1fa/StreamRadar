<?php

namespace App\Services;

use App\Http\Requests\UpdateSettingsRequest;
use App\Models\AlertRule;
use App\Models\BlacklistRule;
use App\Models\Category;
use App\Models\Setting;
use App\Models\TagFilter;
use App\Models\TrackedChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Validates a configuration backup against an explicit schema and applies it
 * atomically. Every section is optional; unknown keys are rejected, every
 * setting goes through the same rules as the Settings page, and alert
 * templates through AlertTemplateValidator.
 */
class ConfigImportService
{
    public const MAX_ITEMS = 2000;

    public function __construct(private AlertTemplateValidator $templates) {}

    /**
     * @return array<string, int> counts per section
     *
     * @throws ValidationException
     */
    public function import(array $data): array
    {
        $validated = $this->validate($data);

        return DB::transaction(function () use ($validated) {
            $counts = ['settings' => 0, 'categories' => 0, 'channels' => 0, 'blacklist' => 0, 'tag_filters' => 0, 'alerts' => 0];

            foreach (UpdateSettingsRequest::normalize($validated['settings'] ?? []) as $key => $value) {
                Setting::set($key, $value);
                $counts['settings']++;
            }

            foreach ($validated['categories'] ?? [] as $cat) {
                Category::updateOrCreate(['twitch_id' => $cat['twitch_id']], collect($cat)->except('twitch_id')->toArray());
                $counts['categories']++;
            }

            foreach ($validated['channels'] ?? [] as $ch) {
                $ch['user_login'] = strtolower($ch['user_login']);
                TrackedChannel::updateOrCreate(['twitch_user_id' => $ch['twitch_user_id']], collect($ch)->except('twitch_user_id')->toArray());
                $counts['channels']++;
            }

            foreach ($validated['blacklist'] ?? [] as $rule) {
                $value = $rule['type'] === 'channel' ? strtolower($rule['value']) : $rule['value'];
                BlacklistRule::updateOrCreate(['type' => $rule['type'], 'value' => $value], collect($rule)->except(['type', 'value'])->toArray());
                $counts['blacklist']++;
            }

            foreach ($validated['tag_filters'] ?? [] as $tf) {
                TagFilter::updateOrCreate(['tag' => $tf['tag']], collect($tf)->except('tag')->toArray());
                $counts['tag_filters']++;
            }

            if (! empty($validated['alerts'])) {
                $twitchToId = Category::pluck('id', 'twitch_id')->toArray();

                foreach ($validated['alerts'] as $alert) {
                    $data = collect($alert)->except(['name', 'category_twitch_id', 'category_twitch_ids'])->toArray();
                    $data['category_id'] = ! empty($alert['category_twitch_id']) ? ($twitchToId[$alert['category_twitch_id']] ?? null) : null;
                    $data['category_ids'] = ! empty($alert['category_twitch_ids'])
                        ? array_values(array_filter(array_map(fn ($tid) => $twitchToId[$tid] ?? null, $alert['category_twitch_ids'])))
                        : null;
                    $data['match_mode'] = $alert['match_mode'] ?? 'always';
                    $data['notification_template'] = $this->templates->validate($alert['notification_template'] ?? null);
                    if (! empty($data['streamer_login'])) {
                        $data['streamer_login'] = strtolower($data['streamer_login']);
                    }

                    AlertRule::updateOrCreate(['name' => $alert['name']], $data);
                    $counts['alerts']++;
                }
            }

            return $counts;
        });
    }

    /**
     * @throws ValidationException
     */
    public function validate(array $data): array
    {
        $unknown = array_diff(array_keys($data), ['settings', 'categories', 'channels', 'blacklist', 'tag_filters', 'alerts', 'exported_at', 'format', 'app']);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['file' => 'Unsupported section(s): '.implode(', ', array_map('strval', $unknown))]);
        }

        $max = self::MAX_ITEMS;
        $login = 'regex:/^[A-Za-z0-9_]{1,64}$/';
        $stringList = ['nullable', 'array', 'max:200'];

        $rules = [
            'settings' => ['sometimes', 'array'],
            'categories' => ['sometimes', 'array', "max:{$max}"],
            'categories.*.twitch_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'categories.*.name' => ['required', 'string', 'max:255'],
            'categories.*.box_art_url' => ['nullable', 'string', 'max:1000', 'starts_with:https://'],
            'categories.*.is_active' => ['sometimes', 'boolean'],
            'categories.*.notifications_enabled' => ['sometimes', 'boolean'],
            'categories.*.use_global_filters' => ['sometimes', 'boolean'],
            'categories.*.filter_source' => ['nullable', 'string', 'max:100'],
            'categories.*.min_viewers' => ['nullable', 'integer', 'min:0'],
            'categories.*.min_avg_viewers' => ['nullable', 'integer', 'min:0'],
            'categories.*.languages' => $stringList,
            'categories.*.languages.*' => ['string', 'max:10'],
            'categories.*.keywords' => $stringList,
            'categories.*.keywords.*' => ['string', 'max:100'],
            'categories.*.tags' => $stringList,
            'categories.*.tags.*' => ['string', 'max:100'],

            'channels' => ['sometimes', 'array', "max:{$max}"],
            'channels.*.twitch_user_id' => ['required', 'string', 'max:64'],
            'channels.*.user_login' => ['required', 'string', $login],
            'channels.*.user_name' => ['nullable', 'string', 'max:255'],
            'channels.*.profile_image_url' => ['nullable', 'string', 'max:1000', 'starts_with:https://'],
            'channels.*.is_active' => ['sometimes', 'boolean'],

            'blacklist' => ['sometimes', 'array', "max:{$max}"],
            'blacklist.*.type' => ['required', 'in:channel,keyword,tag'],
            'blacklist.*.value' => ['required', 'string', 'max:255'],
            'blacklist.*.twitch_user_id' => ['nullable', 'string', 'max:64'],
            'blacklist.*.profile_image_url' => ['nullable', 'string', 'max:1000', 'starts_with:https://'],

            'tag_filters' => ['sometimes', 'array', "max:{$max}"],
            'tag_filters.*.tag' => ['required', 'string', 'max:100'],
            'tag_filters.*.min_viewers' => ['nullable', 'integer', 'min:0'],
            'tag_filters.*.min_avg_viewers' => ['nullable', 'integer', 'min:0'],
            'tag_filters.*.languages' => $stringList,
            'tag_filters.*.languages.*' => ['string', 'max:10'],
            'tag_filters.*.keywords' => $stringList,
            'tag_filters.*.keywords.*' => ['string', 'max:100'],

            'alerts' => ['sometimes', 'array', "max:{$max}"],
            'alerts.*.name' => ['required', 'string', 'max:255'],
            'alerts.*.streamer_login' => ['nullable', 'string', $login],
            'alerts.*.category_twitch_id' => ['nullable', 'string', 'max:64'],
            'alerts.*.category_twitch_ids' => ['nullable', 'array', 'max:200'],
            'alerts.*.category_twitch_ids.*' => ['string', 'max:64'],
            'alerts.*.category_tags' => $stringList,
            'alerts.*.category_tags.*' => ['string', 'max:100'],
            'alerts.*.match_mode' => ['nullable', 'in:always,first_time'],
            'alerts.*.min_viewers' => ['nullable', 'integer', 'min:0'],
            'alerts.*.min_avg_viewers' => ['nullable', 'integer', 'min:0'],
            'alerts.*.language' => ['nullable', 'string', 'max:10'],
            'alerts.*.keywords' => $stringList,
            'alerts.*.keywords.*' => ['string', 'max:100'],
            'alerts.*.notify_email' => ['sometimes', 'boolean'],
            'alerts.*.notify_discord' => ['sometimes', 'boolean'],
            'alerts.*.notify_telegram' => ['sometimes', 'boolean'],
            'alerts.*.notify_webhook' => ['sometimes', 'boolean'],
            'alerts.*.notify_on_category_change' => ['sometimes', 'boolean'],
            'alerts.*.notify_on_stream_start' => ['sometimes', 'boolean'],
            'alerts.*.is_active' => ['sometimes', 'boolean'],
            'alerts.*.notification_template' => ['nullable', 'array'],
        ];

        $validated = Validator::make($data, $rules)->validate();

        if (isset($data['settings'])) {
            $settings = is_array($data['settings']) ? $data['settings'] : [];
            $unknownKeys = array_diff(array_keys($settings), Setting::MANAGED_KEYS);
            if ($unknownKeys !== []) {
                throw ValidationException::withMessages(['settings' => 'Unsupported setting(s): '.implode(', ', array_map('strval', $unknownKeys))]);
            }
            foreach ($settings as $key => $value) {
                if (! is_scalar($value) && $value !== null) {
                    throw ValidationException::withMessages(["settings.{$key}" => 'Setting values must be scalar.']);
                }
                if (Setting::isSecret($key) && UpdateSettingsRequest::looksMasked($value)) {
                    unset($settings[$key]);
                }
            }
            $validated['settings'] = Validator::make($settings, UpdateSettingsRequest::settingRules())->validate();
        }

        foreach ($validated['alerts'] ?? [] as $i => $alert) {
            try {
                $this->templates->validate($alert['notification_template'] ?? null);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(["alerts.{$i}.notification_template" => 'Alert "'.$alert['name'].'" has an invalid notification template.']);
            }
        }

        // Explicitly drop anything that did not pass through a rule (e.g. extra keys per item).
        foreach (['categories', 'channels', 'blacklist', 'tag_filters', 'alerts'] as $section) {
            if (isset($validated[$section])) {
                $validated[$section] = array_values($validated[$section]);
            }
        }

        return $validated;
    }
}
