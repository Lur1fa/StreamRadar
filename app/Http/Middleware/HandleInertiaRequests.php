<?php

namespace App\Http\Middleware;

use App\Models\AlertRule;
use App\Models\BlacklistRule;
use App\Models\Category;
use App\Models\Setting;
use App\Models\Stream;
use App\Models\UserSetting;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user?->toPublicArray(),
            ],
            // Counts of the signed-in account's own data.
            'stats' => fn () => $user ? [
                'categories_count' => Category::ownedBy($user)->where('is_active', true)->count(),
                'streams_count' => Stream::ownedBy($user)->count(),
                'alerts_count' => AlertRule::ownedBy($user)->where('is_active', true)->count(),
                'blacklist_count' => BlacklistRule::ownedBy($user)->count(),
            ] : null,
            'appSettings' => fn () => [
                'theme' => $user ? UserSetting::get($user, 'theme', 'system') : 'system',
                'last_sync_at' => $user ? Setting::get('last_sync_at') : null,
                'sync_frequency_minutes' => Setting::syncFrequencyMinutes(),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }
}
