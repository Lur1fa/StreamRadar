<?php

namespace App\Console\Commands;

use App\Services\Auth\SetupTokenService;
use Illuminate\Console\Command;

class SetupTokenCommand extends Command
{
    protected $signature = 'auth:setup-token
                            {--if-needed : Only print a token when no user accounts exist yet (used by the Docker entrypoint)}';

    protected $description = 'Generate a one-time token for creating the first administrator account via /setup';

    public function handle(SetupTokenService $setup): int
    {
        if (! $setup->isSetupRequired()) {
            if ($this->option('if-needed')) {
                return self::SUCCESS;
            }

            $this->error('Setup is already complete: user accounts exist. Use "php artisan user:create --admin" to add administrators.');

            return self::FAILURE;
        }

        $token = $setup->issue();
        $url = rtrim((string) config('app.url'), '/').'/setup';

        $this->newLine();
        $this->line('  ╭──────────────────────────────────────────────────────────────╮');
        $this->line('  │  StreamRadar has no user accounts yet.                       │');
        $this->line('  │  Open the setup page and paste this one-time token to create │');
        $this->line('  │  the first administrator (valid for '.str_pad($setup->ttlMinutes().' minutes', 25).'│');
        $this->line('  ╰──────────────────────────────────────────────────────────────╯');
        $this->newLine();
        $this->line("  Setup page:  {$url}");
        $this->line("  Setup token: {$token}");
        $this->newLine();
        $this->line('  Alternatively create the account directly: php artisan user:create --admin');
        $this->newLine();

        return self::SUCCESS;
    }
}
