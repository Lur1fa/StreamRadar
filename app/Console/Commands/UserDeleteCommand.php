<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ManagesAccounts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UserDeleteCommand extends Command
{
    use ManagesAccounts;

    protected $signature = 'user:delete {email : E-mail of the account} {--force : Skip confirmation}';

    protected $description = 'Delete a user account';

    public function handle(): int
    {
        $user = $this->accountOrFail((string) $this->argument('email'));
        if (! $user) {
            return self::FAILURE;
        }

        if ($user->isLastActiveAdmin()) {
            $this->error('Refusing to delete the last administrator.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Delete {$user->email} ({$user->role}) together with its tracking, alerts, blacklist and history?")) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($user) {
            $user->endAllSessions();
            $user->delete();
        });

        $this->info('Account deleted.');

        return self::SUCCESS;
    }
}
