<?php

namespace App\Http\Controllers;

use App\Models\HistoryEvent;
use App\Models\Setting;
use App\Models\UserSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        HistoryEvent::pruneExpiredFor($user);
        $retentionDays = (int) UserSetting::get($user, 'history_retention_days', Setting::DEFAULTS['history_retention_days']);
        $query = HistoryEvent::ownedBy($user)->orderByDesc('created_at');

        if ($request->filled('type')) {
            $query->ofType($request->input('type'));
        } else {
            $query->where('type', '!=', HistoryEvent::SYNC_COMPLETED);
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('streamer_login', 'like', "%{$search}%")
                    ->orWhere('streamer_name', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('category_name', 'like', "%{$search}%");
            });
        }

        return Inertia::render('History', [
            'events' => $query->paginate(50)->withQueryString(),
            'filters' => [
                'type' => $request->input('type'),
                'search' => $request->input('search'),
            ],
            'historyRetentionDays' => $retentionDays,
        ]);
    }

    public function clear(Request $request): RedirectResponse
    {
        HistoryEvent::ownedBy($request->user())->delete();

        return back()->with('success', 'History cleared.');
    }
}
