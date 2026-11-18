<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\SyncService;
use App\Services\TwitchApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    /**
     * "Sync now" refreshes the signed-in account only; the scheduled sync
     * keeps covering everyone.
     */
    public function trigger(Request $request, SyncService $sync, TwitchApiService $twitch): RedirectResponse
    {
        if (! $twitch->isConfigured()) {
            return back()->with('error', TwitchApiService::NOT_CONFIGURED);
        }

        $result = $sync->syncAccount($request->user());

        return back()->with('success', "Sync complete: {$result->newStreams} new, {$result->updatedStreams} updated, {$result->endedStreams} ended.");
    }

    public function status(): JsonResponse
    {
        return response()->json([
            'last_sync_at' => Setting::get('last_sync_at'),
            'sync_frequency_minutes' => Setting::syncFrequencyMinutes(),
        ]);
    }
}
