<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Setting;
use App\Models\Stream;
use App\Models\TagFilter;
use App\Models\TrackedChannel;
use App\Models\UserSetting;
use App\Services\SyncService;
use App\Services\TwitchApiService;
use App\Support\Redactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TrackingController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $sort = $request->input('sort', 'name');
        $query = Category::ownedBy($user)
            ->withCount('streams')
            ->withSum('streams', 'viewer_count');

        $query = match ($sort) {
            'streams' => $query->orderByDesc('streams_count'),
            'viewers' => $query->orderByDesc('streams_sum_viewer_count'),
            default => $query->orderBy('name'),
        };

        $channels = TrackedChannel::ownedBy($user)->orderBy('user_name')->get();
        $defaults = UserSetting::getMany($user, ['global_min_viewers', 'global_min_avg_viewers', 'global_languages', 'global_keywords'], Setting::DEFAULTS);

        return Inertia::render('Tracking', [
            'categories' => $query->get(),
            'channels' => $channels,
            'sort' => $sort,
            'tab' => $request->input('tab', 'categories'),
            'tagFilters' => TagFilter::ownedBy($user)->orderBy('tag')->get(),
            'globalFilters' => [
                'min_viewers' => (int) $defaults['global_min_viewers'],
                'min_avg_viewers' => (int) $defaults['global_min_avg_viewers'],
                'languages' => json_decode((string) $defaults['global_languages'], true) ?: [],
                'keywords' => json_decode((string) $defaults['global_keywords'], true) ?: [],
            ],
        ]);
    }

    public function searchCategories(Request $request, TwitchApiService $twitch): JsonResponse
    {
        $request->validate(['query' => 'required|string|min:1']);

        if (! $twitch->isConfigured()) {
            return response()->json(['error' => 'Twitch API not configured'], 422);
        }

        try {
            $results = $twitch->searchCategories($request->input('query'));
            $trackedIds = Category::ownedBy($request->user())->pluck('twitch_id')->toArray();
            $queryLower = strtolower($request->input('query'));

            $results = array_map(function ($cat) use ($trackedIds) {
                $cat['is_tracked'] = in_array($cat['id'], $trackedIds);

                return $cat;
            }, $results);

            // Sort: exact match first, then prefix matches, then rest
            usort($results, function ($a, $b) use ($queryLower) {
                $aName = strtolower($a['name']);
                $bName = strtolower($b['name']);
                $aExact = $aName === $queryLower;
                $bExact = $bName === $queryLower;
                if ($aExact !== $bExact) {
                    return $bExact <=> $aExact;
                }
                $aPrefix = str_starts_with($aName, $queryLower);
                $bPrefix = str_starts_with($bName, $queryLower);
                if ($aPrefix !== $bPrefix) {
                    return $bPrefix <=> $aPrefix;
                }

                return strlen($aName) <=> strlen($bName);
            });

            return response()->json($results);
        } catch (\Exception $e) {
            // Every account can search now; keep provider error details in the log.
            Log::warning('Twitch category search failed', ['error' => Redactor::message($e->getMessage())]);

            return response()->json(['error' => 'The Twitch search failed. Please try again.'], 500);
        }
    }

    public function storeCategory(Request $request, SyncService $sync, TwitchApiService $twitch): \Illuminate\Http\RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'twitch_id' => ['required', 'string', Rule::unique('categories', 'twitch_id')->where('owner_id', $user->id)],
            'name' => 'required|string|max:255',
            'box_art_url' => 'nullable|string',
        ]);

        $category = Category::createFor($user, [
            ...$validated,
            'is_active' => true,
            'notifications_enabled' => true,
            'use_global_filters' => true,
        ]);

        if ($twitch->isConfigured()) {
            try {
                $sync->syncCategory($category, silentAlerts: true);
            } catch (\Exception $e) {
                // Sync will catch up later
            }
        }

        return back()->with('success', "Tracking \"{$validated['name']}\" — {$category->streams()->count()} streams found.");
    }

    public function updateCategory(Request $request, Category $category): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'is_active' => 'sometimes|boolean',
            'notifications_enabled' => 'sometimes|boolean',
            'use_global_filters' => 'sometimes|boolean',
            'min_viewers' => 'nullable|integer|min:0',
            'min_avg_viewers' => 'nullable|integer|min:0',
            'languages' => 'nullable|array',
            'keywords' => 'nullable|array',
            'tags' => 'nullable|array',
            'filter_source' => 'sometimes|string|max:100',
        ]);

        // Keep use_global_filters in sync for backwards compat
        if (isset($validated['filter_source'])) {
            $validated['use_global_filters'] = $validated['filter_source'] === 'global';
        }

        $category->update($validated);

        return back()->with('success', "Category \"{$category->name}\" updated.");
    }

    // ── Tag Filters ──────────────────────────────────────────────────

    public function storeTagFilter(Request $request): \Illuminate\Http\RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'tag' => ['required', 'string', 'max:100', Rule::unique('tag_filters', 'tag')->where('owner_id', $user->id)],
            'min_viewers' => 'nullable|integer|min:0',
            'min_avg_viewers' => 'nullable|integer|min:0',
            'languages' => 'nullable|array',
            'keywords' => 'nullable|array',
        ]);

        TagFilter::createFor($user, $validated);

        return back()->with('success', "Tag filter \"{$validated['tag']}\" created.");
    }

    public function updateTagFilter(Request $request, TagFilter $tagFilter): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'min_viewers' => 'nullable|integer|min:0',
            'min_avg_viewers' => 'nullable|integer|min:0',
            'languages' => 'nullable|array',
            'keywords' => 'nullable|array',
        ]);

        $tagFilter->update($validated);

        return back()->with('success', "Tag filter \"{$tagFilter->tag}\" updated.");
    }

    public function destroyTagFilter(TagFilter $tagFilter): \Illuminate\Http\RedirectResponse
    {
        $tag = $tagFilter->tag;

        // Reset this account's categories using the tag filter back to global
        Category::ownedBy((int) $tagFilter->owner_id)->where('filter_source', "tag:{$tag}")->update([
            'filter_source' => 'global',
            'use_global_filters' => true,
        ]);

        $tagFilter->delete();

        return back()->with('success', "Tag filter \"{$tag}\" removed.");
    }

    public function destroyCategory(Category $category): \Illuminate\Http\RedirectResponse
    {
        $name = $category->name;
        $category->streams()->delete();
        $category->delete();

        return back()->with('success', "Category \"{$name}\" removed.");
    }

    public function syncCategory(Category $category, SyncService $sync, TwitchApiService $twitch): \Illuminate\Http\RedirectResponse
    {
        if (! $twitch->isConfigured()) {
            return back()->with('error', 'Twitch API not configured.');
        }

        $result = $sync->syncCategory($category);

        // Remove streams from this category that didn't pass filters
        $staleQuery = Stream::ownedBy((int) $category->owner_id)->where('category_id', $category->id);
        if (! empty($result['stream_ids'])) {
            $staleQuery->whereNotIn('twitch_id', $result['stream_ids']);
        }
        $staleQuery->delete();

        $sync->getAlertService()->sendNotifications($result['triggered_alerts']);

        return back()->with('success', "\"{$category->name}\" synced — {$result['new']} new, {$result['updated']} updated.");
    }

    public function storeChannel(Request $request, TwitchApiService $twitch, SyncService $sync): \Illuminate\Http\RedirectResponse
    {
        $request->validate(['user_login' => 'required|string|max:255']);

        $user = $request->user();
        $login = strtolower($request->input('user_login'));

        if (TrackedChannel::ownedBy($user)->where('user_login', $login)->exists()) {
            return back()->with('error', "Channel \"{$login}\" is already tracked.");
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
                    $login = strtolower($users[0]['login']);
                    $avatar = $users[0]['profile_image_url'] ?? null;
                } else {
                    return back()->with('error', "Channel \"{$login}\" not found on Twitch.");
                }
            } catch (\Exception $e) {
                // Continue with what we have
            }
        }

        // The login may have been normalised by Twitch; check again before inserting.
        if (TrackedChannel::ownedBy($user)->where(fn ($q) => $q->where('user_login', $login)->orWhere('twitch_user_id', $userId))->exists()) {
            return back()->with('error', "Channel \"{$userName}\" is already tracked.");
        }

        TrackedChannel::createFor($user, [
            'twitch_user_id' => $userId,
            'user_login' => $login,
            'user_name' => $userName,
            'profile_image_url' => $avatar,
        ]);

        // Sync immediately
        if ($twitch->isConfigured()) {
            try {
                $sync->syncTrackedChannels($user, silentAlerts: true);
            } catch (\Exception $e) {
                // Will catch up
            }
        }

        return back()->with('success', "Tracking channel \"{$userName}\".");
    }

    public function destroyChannel(TrackedChannel $channel): \Illuminate\Http\RedirectResponse
    {
        $name = $channel->user_name;
        $this->removeChannelStreams($channel);
        $channel->delete();

        return back()->with('success', "Channel \"{$name}\" removed.");
    }

    public function destroyChannelByLogin(Request $request, string $login): \Illuminate\Http\RedirectResponse
    {
        $channel = TrackedChannel::ownedBy($request->user())->where('user_login', strtolower($login))->first();
        if (! $channel) {
            return back()->with('error', 'Channel not found.');
        }

        $name = $channel->user_name;
        $this->removeChannelStreams($channel);
        $channel->delete();

        return back()->with('success', "Channel \"{$name}\" untracked.");
    }

    private function removeChannelStreams(TrackedChannel $channel): void
    {
        // Remove the owner's streams that only exist because of channel tracking (no tracked category)
        Stream::ownedBy((int) $channel->owner_id)->where('user_login', $channel->user_login)
            ->where(function ($q) {
                $q->whereNull('category_id')
                    ->orWhereDoesntHave('category', fn ($c) => $c->where('is_active', true));
            })
            ->delete();
    }
}
