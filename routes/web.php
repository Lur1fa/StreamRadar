<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\OidcController;
use App\Http\Controllers\Auth\SetupController;
use App\Http\Controllers\BlacklistController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public (guest) routes
|--------------------------------------------------------------------------
| Login, SSO hand-off and the one-time first-admin setup. Everything else
| requires an authenticated session.
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:login')->name('login.attempt');

    Route::get('/auth/oidc/redirect', [OidcController::class, 'redirect'])->middleware('throttle:oidc')->name('oidc.redirect');
    Route::get('/auth/oidc/callback', [OidcController::class, 'callback'])->middleware('throttle:oidc')->name('oidc.callback');

    Route::get('/setup', [SetupController::class, 'show'])->name('setup.show');
    Route::post('/setup', [SetupController::class, 'store'])->middleware('throttle:setup')->name('setup.store');
});

Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

/*
|--------------------------------------------------------------------------
| Authenticated, read-only (role: user or admin)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/tracking', [TrackingController::class, 'index'])->name('tracking.index');
    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::get('/blacklist', [BlacklistController::class, 'index'])->name('blacklist.index');
    Route::get('/history', [HistoryController::class, 'index'])->name('history.index');
    Route::get('/sync/status', [SyncController::class, 'status'])->name('sync.status');

    Route::get('/account', [AccountController::class, 'show'])->name('account.show');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password');
});

/*
|--------------------------------------------------------------------------
| Administrator-only: every mutation, settings, users, outbound tests
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'can:admin'])->group(function () {
    // Tracking
    Route::get('/tracking/search', [TrackingController::class, 'searchCategories'])->name('tracking.search');
    Route::post('/tracking/categories', [TrackingController::class, 'storeCategory'])->name('tracking.store-category');
    Route::put('/tracking/categories/{category}', [TrackingController::class, 'updateCategory'])->name('tracking.update-category');
    Route::delete('/tracking/categories/{category}', [TrackingController::class, 'destroyCategory'])->name('tracking.destroy-category');
    Route::post('/tracking/categories/{category}/sync', [TrackingController::class, 'syncCategory'])->middleware('throttle:sync')->name('tracking.sync-category');
    Route::post('/tracking/tag-filters', [TrackingController::class, 'storeTagFilter'])->name('tracking.store-tag-filter');
    Route::put('/tracking/tag-filters/{tagFilter}', [TrackingController::class, 'updateTagFilter'])->name('tracking.update-tag-filter');
    Route::delete('/tracking/tag-filters/{tagFilter}', [TrackingController::class, 'destroyTagFilter'])->name('tracking.destroy-tag-filter');
    Route::post('/tracking/channels', [TrackingController::class, 'storeChannel'])->name('tracking.store-channel');
    Route::delete('/tracking/channels/{channel}', [TrackingController::class, 'destroyChannel'])->name('tracking.destroy-channel');
    Route::delete('/tracking/channels/by-login/{login}', [TrackingController::class, 'destroyChannelByLogin'])->name('tracking.destroy-channel-by-login');

    // Alerts
    Route::post('/alerts', [AlertController::class, 'store'])->name('alerts.store');
    Route::put('/alerts/{alert}', [AlertController::class, 'update'])->name('alerts.update');
    Route::delete('/alerts/{alert}', [AlertController::class, 'destroy'])->name('alerts.destroy');

    // Blacklist
    Route::post('/blacklist', [BlacklistController::class, 'store'])->name('blacklist.store');
    Route::delete('/blacklist/{blacklist}', [BlacklistController::class, 'destroy'])->name('blacklist.destroy');

    // History
    Route::delete('/history', [HistoryController::class, 'clear'])->name('history.clear');

    // Settings
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::get('/settings/check-update', [SettingsController::class, 'checkUpdate'])->name('settings.check-update');
    Route::get('/settings/export', [SettingsController::class, 'export'])->name('settings.export');
    Route::post('/settings/import', [SettingsController::class, 'import'])->name('settings.import');
    Route::middleware('throttle:settings-tests')->group(function () {
        Route::post('/settings/test-twitch', [SettingsController::class, 'testTwitch'])->name('settings.test-twitch');
        Route::post('/settings/test-email', [SettingsController::class, 'testEmail'])->name('settings.test-email');
        Route::post('/settings/test-discord', [SettingsController::class, 'testDiscord'])->name('settings.test-discord');
        Route::post('/settings/test-telegram', [SettingsController::class, 'testTelegram'])->name('settings.test-telegram');
        Route::post('/settings/test-webhook', [SettingsController::class, 'testWebhook'])->name('settings.test-webhook');
    });

    // Users
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::put('/users/sso', [UserController::class, 'updateSso'])->name('users.sso'); // must precede /users/{user}
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

    // Sync
    Route::post('/sync', [SyncController::class, 'trigger'])->middleware('throttle:sync')->name('sync.trigger');
});
