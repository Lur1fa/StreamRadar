<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UserDeleteCommand extends Command
{
    protected $signature = 'user:delete {email : E-mail of the account} {--force : Skip confirmation}';

    protected $description = 'Delete a user account';

    public function handle(): int
    {
        $user = User::findByEmail($this->argument('email'));
        if (! $user) {
            $this->error('No account with that e-mail address.');

            return self::FAILURE;
        }

        if ($user->isAdmin() && User::admins()->active()->where('id', '!=', $user->id)->doesntExist()) {
            $this->error('Refusing to delete the last administrator.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Delete {$user->email} ({$user->role}) together with its tracking, alerts, blacklist and history?")) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($user) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->delete();
        });

        $this->info('Account deleted.');

        return self::SUCCESS;
    }
}
