<?php

namespace App\Http\Middleware;

use App\Models\AlertRule;
use App\Models\BlacklistRule;
use App\Models\Category;
use App\Models\Setting;
use App\Models\Stream;
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
            'stats' => fn () => $user ? [
                'categories_count' => Category::where('is_active', true)->count(),
                'streams_count' => Stream::count(),
                'alerts_count' => AlertRule::where('is_active', true)->count(),
                'blacklist_count' => BlacklistRule::count(),
            ] : null,
            'appSettings' => fn () => [
                'theme' => Setting::get('theme', 'system'),
                'last_sync_at' => $user ? Setting::get('last_sync_at') : null,
                'sync_frequency_minutes' => (int) Setting::get('sync_frequency_minutes', 5),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }
}
