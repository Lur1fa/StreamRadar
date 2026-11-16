<?php

use App\Models\AlertRule;
use App\Models\BlacklistRule;
use App\Models\Category;
use App\Models\Setting;
use App\Models\Stream;

test('shares stats with correct counts', function () {
    $activeCategory = Category::factory()->create(['is_active' => true]);
    Category::factory()->inactive()->create();
    Stream::factory()->count(3)->forCategory($activeCategory)->create();
    AlertRule::factory()->count(2)->create(['is_active' => true]);
    AlertRule::factory()->inactive()->create();
    BlacklistRule::factory()->create();

    $this->get('/')
        ->assertInertia(fn ($page) => $page
            ->where('stats.categories_count', 1)
            ->where('stats.streams_count', 3)
            ->where('stats.alerts_count', 2)
            ->where('stats.blacklist_count', 1)
        );
});

test('shares appSettings with theme', function () {
    Setting::set('theme', 'dark');

    $this->get('/')
        ->assertInertia(fn ($page) => $page
            ->where('appSettings.theme', 'dark')
        );
});

test('shares the signed-in user without credentials or SSO identifiers', function () {
    $this->get('/')
        ->assertInertia(fn ($page) => $page
            ->has('auth.user', fn ($user) => $user
                ->has('id')->has('name')->has('email')
                ->where('role', 'admin')
                ->where('is_admin', true)
                ->missing('password')
                ->missing('oidc_subject')
                ->etc()
            )
        );
});

test('shares flash success message', function () {
    $this->withSession(['success' => 'It worked!'])
        ->get('/')
        ->assertInertia(fn ($page) => $page
            ->where('flash.success', 'It worked!')
        );
});
