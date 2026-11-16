<?php

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Secret settings (API secrets, SMTP password, bot tokens, webhook URLs) are
 * now stored encrypted with APP_KEY. Encrypt any plaintext values that exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('settings')->whereIn('key', Setting::SECRET_KEYS)->get();

        foreach ($rows as $row) {
            if ($row->value === null || $row->value === '') {
                continue;
            }

            try {
                Crypt::decryptString($row->value);
                continue; // already encrypted
            } catch (DecryptException) {
                // plaintext → encrypt below
            }

            DB::table('settings')->where('id', $row->id)->update([
                'value' => Crypt::encryptString($row->value),
                'updated_at' => now(),
            ]);
            Cache::forget("setting.{$row->key}");
        }
    }

    public function down(): void
    {
        // Values stay encrypted; decrypting on downgrade would re-expose them.
    }
};
