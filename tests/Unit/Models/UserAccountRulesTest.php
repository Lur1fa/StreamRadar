<?php

use Illuminate\Support\Facades\DB;

test('the last active administrator is recognised', function () {
    $admin = adminUser();
    $other = adminUser(['disabled_at' => now()]);

    expect($admin->isLastActiveAdmin())->toBeTrue()
        ->and(regularUser()->isLastActiveAdmin())->toBeFalse();

    $other->forceFill(['disabled_at' => null])->save();
    expect($admin->isLastActiveAdmin())->toBeFalse();
});

test('ending all sessions only signs out that account', function () {
    $alice = regularUser();
    $bob = regularUser();
    foreach ([$alice, $bob] as $i => $user) {
        DB::table('sessions')->insert(['id' => "s{$i}", 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    }

    $alice->endAllSessions();

    expect(DB::table('sessions')->pluck('user_id')->all())->toBe([$bob->id]);
});
