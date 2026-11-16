<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * StreamRadar used to keep a single username/password pair in the settings
 * table (auth_username / auth_password) and ran fully open when they were
 * absent. Authentication is now account based. To avoid locking existing
 * installations out, a legacy credential pair is converted into a local admin
 * account whose login e-mail is the old username (suffixed with
 * "@streamradar.local" when it was not an e-mail address). The bcrypt hash is
 * kept, so the old password keeps working.
 */
return new class extends Migration
{
    public function up(): void
    {
        $username = DB::table('settings')->where('key', 'auth_username')->value('value');
        $hash = DB::table('settings')->where('key', 'auth_password')->value('value');

        if ($username && $hash && DB::table('users')->count() === 0) {
            $email = str_contains($username, '@') ? strtolower($username) : strtolower($username).'@streamradar.local';

            DB::table('users')->insert([
                'name' => $username,
                'email' => $email,
                'role' => 'admin',
                'password' => $hash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info("Legacy StreamRadar credentials converted to local admin account [{$email}].");
        }

        DB::table('settings')->whereIn('key', ['auth_username', 'auth_password'])->delete();
        Cache::forget('setting.auth_username');
        Cache::forget('setting.auth_password');
    }

    public function down(): void
    {
        // Intentionally irreversible: the open-access mode is gone for good.
    }
};
