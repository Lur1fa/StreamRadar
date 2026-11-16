<?php

namespace App\Http\Controllers;

use App\Models\Stream;
use App\Models\TrackedChannel;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $trackedLogins = TrackedChannel::ownedBy($user)->where('is_active', true)->pluck('user_login')->toArray();

        $query = Stream::with('category')
            ->ownedBy($user)
            ->whereNull('missing_since')
            ->where('thumbnail_url', 'not like', '%ttv-static/404_preview%')
            ->where(function ($q) use ($trackedLogins) {
                $q->whereHas('category', fn ($c) => $c->where('is_active', true));
                if (! empty($trackedLogins)) {
                    $q->orWhereIn('user_login', $trackedLogins);
                }
            });

        // Quick filters
        if ($request->filled('lang')) {
            $query->where('language', $request->input('lang'));
        }
        if ($request->filled('min_viewers')) {
            $query->where('viewer_count', '>=', $request->integer('min_viewers'));
        }
        if ($request->input('hide_mature') === '1') {
            $query->where('is_mature', false);
        }

        // Search (name, title, tags)
        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('user_name', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhereJsonContains('tags', (string) $search);
            });
        }

        // Sort
        $sort = $request->string('sort', 'viewers_desc');
        $query = match ((string) $sort) {
            'viewers_asc' => $query->orderBy('viewer_count', 'asc'),
            'name' => $query->orderBy('user_name', 'asc'),
            'started_at' => $query->orderBy('started_at', 'desc'),
            default => $query->orderBy('viewer_count', 'desc'),
        };

        $streams = $query->get();

        // Available languages for filter
        $languages = Stream::ownedBy($user)->whereNotNull('language')
            ->select('language')
            ->distinct()
            ->pluck('language')
            ->sort()
            ->values();

        return Inertia::render('Dashboard', [
            'streams' => $streams,
            'trackedLogins' => $trackedLogins,
            'languages' => $languages,
            'filters' => [
                'sort' => (string) $sort,
                'search' => $request->input('search'),
                'lang' => $request->input('lang'),
                'min_viewers' => $request->input('min_viewers'),
                'hide_mature' => $request->input('hide_mature'),
            ],
        ]);
    }
}
