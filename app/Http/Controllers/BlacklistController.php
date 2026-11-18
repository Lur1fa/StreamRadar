<?php

namespace App\Http\Controllers;

use App\Models\BlacklistRule;
use App\Models\Stream;
use App\Services\TwitchUserLookup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BlacklistController extends Controller
{
    /** Blacklist page tab of each rule type. */
    private const TABS = [
        BlacklistRule::CHANNEL => 'channels',
        BlacklistRule::KEYWORD => 'keywords',
        BlacklistRule::TAG => 'tags',
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Blacklist', [
            'channels' => BlacklistRule::ownedBy($user)->channels()->orderByDesc('created_at')->get(),
            'keywords' => BlacklistRule::ownedBy($user)->keywords()->orderByDesc('created_at')->get(),
            'tags' => BlacklistRule::ownedBy($user)->tags()->orderByDesc('created_at')->get(),
            'tab' => $request->input('tab', 'channels'),
        ]);
    }

    public function store(Request $request, TwitchUserLookup $lookup): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(BlacklistRule::TYPES)],
            'value' => 'required|string|max:255',
        ]);

        $type = $validated['type'];
        $value = $type === BlacklistRule::CHANNEL ? strtolower($validated['value']) : $validated['value'];
        $extra = [];

        // Channels get their Twitch id and avatar when Twitch knows them.
        if ($type === BlacklistRule::CHANNEL && ($user = $lookup->tryFind($value))) {
            $extra = ['twitch_user_id' => $user['id'], 'profile_image_url' => $user['profile_image_url']];
            $value = $user['login'];
        }

        $owner = $request->user();
        BlacklistRule::updateOrCreateFor($owner, ['type' => $type, 'value' => $value], $extra);

        // Remove this account's existing streams matching the blacklist rule
        match ($type) {
            BlacklistRule::CHANNEL => Stream::ownedBy($owner)->where('user_login', $value)->delete(),
            BlacklistRule::KEYWORD => Stream::ownedBy($owner)->where('title', 'like', '%'.strtolower($value).'%')->delete(),
            BlacklistRule::TAG => Stream::ownedBy($owner)->whereJsonContains('tags', $value)->delete(),
        };

        $message = "Added \"{$value}\" to blacklist.";

        // From the blacklist page, go to the rule's tab; otherwise stay on the current page.
        if (str_contains($request->headers->get('referer', ''), '/blacklist')) {
            return redirect()->route('blacklist.index', ['tab' => self::TABS[$type]])->with('success', $message);
        }

        return back()->with('success', $message);
    }

    public function destroy(BlacklistRule $blacklist): RedirectResponse
    {
        $blacklist->delete();

        return back()->with('success', 'Removed from blacklist.');
    }
}
