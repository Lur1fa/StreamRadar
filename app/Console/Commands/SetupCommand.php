<?php

namespace App\Console\Commands;

use App\Services\Auth\SetupTokenService;
use Illuminate\Console\Command;

class SetupCommand extends Command
{
    protected $signature = 'app:setup';

    protected $description = 'Initial setup for StreamRadar';

    public function handle(SetupTokenService $setup): int
    {
        $this->info('Setting up StreamRadar...');

        // Create SQLite database if missing
        $dbPath = database_path('database.sqlite');
        if (config('database.default') === 'sqlite' && ! file_exists($dbPath)) {
            touch($dbPath);
            $this->info('Created SQLite database.');
        }

        // Run migrations
        $this->call('migrate', ['--force' => true]);

        // Nothing to seed: settings that are not stored use Setting::DEFAULTS.
        $this->newLine();

        if ($setup->isSetupRequired()) {
            $this->call('auth:setup-token');
        } else {
            $this->info('StreamRadar is ready! Sign in and configure your Twitch API keys in Settings.');
        }

        return self::SUCCESS;
    }
}
