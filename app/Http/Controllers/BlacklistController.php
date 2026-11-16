<?php

namespace App\Http\Controllers;

use App\Models\BlacklistRule;
use App\Models\Stream;
use App\Services\TwitchApiService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BlacklistController extends Controller
{
    public function index(Request $request): Response
    {
        $tab = $request->input('tab', 'channels');

        $user = $request->user();
        $channels = BlacklistRule::ownedBy($user)->channels()->orderByDesc('created_at')->get();
        $keywords = BlacklistRule::ownedBy($user)->keywords()->orderByDesc('created_at')->get();
        $tags = BlacklistRule::ownedBy($user)->tags()->orderByDesc('created_at')->get();

        return Inertia::render('Blacklist', [
            'channels' => $channels,
            'keywords' => $keywords,
            'tags' => $tags,
            'tab' => $tab,
        ]);
    }

    public function store(Request $request, TwitchApiService $twitch): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'type' => 'required|in:channel,keyword,tag',
            'value' => 'required|string|max:255',
        ]);


        $value = $validated['type'] === 'channel' ? strtolower($validated['value']) : $validated['value'];
        $extra = [];

        // Fetch Twitch data for channels
        if ($validated['type'] === 'channel' && $twitch->isConfigured()) {
            try {
                $users = $twitch->getUsers([$value]);
                if (! empty($users)) {
                    $extra['twitch_user_id'] = $users[0]['id'];
                    $extra['profile_image_url'] = $users[0]['profile_image_url'] ?? null;
                    $value = strtolower($users[0]['login']);
                }
            } catch (\Exception $e) {
                // Continue without Twitch data
            }
        }

        $user = $request->user();

        BlacklistRule::updateOrCreateFor(
            $user,
            ['type' => $validated['type'], 'value' => $value],
            $extra,
        );

        // Remove this account's existing streams matching the blacklist rule
        match ($validated['type']) {
            'channel' => Stream::ownedBy($user)->where('user_login', $value)->delete(),
            'keyword' => Stream::ownedBy($user)->where('title', 'like', '%'.strtolower($value).'%')->delete(),
            'tag' => Stream::ownedBy($user)->whereJsonContains('tags', $value)->delete(),
        };

        // If called from blacklist page, redirect to correct tab; otherwise stay on current page
        $referer = $request->headers->get('referer', '');
        if (str_contains($referer, '/blacklist')) {
            $tabMap = ['channel' => 'channels', 'keyword' => 'keywords', 'tag' => 'tags'];
            return redirect()->route('blacklist.index', ['tab' => $tabMap[$validated['type']]])
                ->with('success', "Added \"{$value}\" to blacklist.");
        }

        return back()->with('success', "Added \"{$value}\" to blacklist.");
    }

    public function destroy(BlacklistRule $blacklist): \Illuminate\Http\RedirectResponse
    {
        $blacklist->delete();

        return back()->with('success', 'Removed from blacklist.');
    }
}
