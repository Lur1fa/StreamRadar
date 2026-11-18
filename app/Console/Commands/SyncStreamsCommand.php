<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\SyncService;
use App\Services\TwitchApiService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncStreamsCommand extends Command
{
    protected $signature = 'streams:sync {--force : Skip frequency check}';

    protected $description = 'Sync live streams from tracked Twitch categories';

    public function handle(SyncService $sync, TwitchApiService $twitch): int
    {
        if (! $twitch->isConfigured()) {
            $this->error(TwitchApiService::NOT_CONFIGURED.' Please set them in Settings.');

            return self::FAILURE;
        }

        // Check if auto sync is enabled (skip if --force)
        if (! $this->option('force') && Setting::get('auto_sync_enabled', '1') === '0') {
            $this->line('Auto sync is disabled.');

            return self::SUCCESS;
        }

        // Check sync frequency (skip if --force)
        if (! $this->option('force')) {
            $lastSync = Setting::get('last_sync_at');
            $frequency = Setting::syncFrequencyMinutes();

            if ($lastSync) {
                $lastSyncTime = Carbon::parse($lastSync);
                $nextAllowed = $lastSyncTime->addMinutes($frequency);

                if (now()->lt($nextAllowed)) {
                    $this->line('Skipping — next sync allowed at '.$nextAllowed->format('H:i:s'));

                    return self::SUCCESS;
                }
            }
        }

        $this->info('Starting stream sync...');
        $result = $sync->sync();

        $this->info("Sync complete in {$result->durationSeconds}s:");
        $this->line("  New streams: {$result->newStreams}");
        $this->line("  Updated: {$result->updatedStreams}");
        $this->line("  Ended: {$result->endedStreams}");
        $this->line("  Alerts triggered: {$result->alertsTriggered}");

        return self::SUCCESS;
    }
}
