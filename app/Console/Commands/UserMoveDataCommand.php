<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountDataService;
use Illuminate\Console\Command;

class UserMoveDataCommand extends Command
{
    protected $signature = 'user:move-data
                            {from : E-mail of the account that has the data}
                            {to : E-mail of the account that should get it}
                            {--replace : Delete the target account\'s own tracking, alerts, blacklist and history first}
                            {--force : Do not ask for confirmation}';

    protected $description = 'Move tracking, alerts, blacklist, history and alert destinations from one account to another';

    public function handle(AccountDataService $accounts): int
    {
        $from = User::findByEmail((string) $this->argument('from'));
        $to = User::findByEmail((string) $this->argument('to'));

        if (! $from || ! $to) {
            $this->error('No account with the e-mail address '.(! $from ? $this->argument('from') : $this->argument('to')).'.');

            return self::FAILURE;
        }

        if ($from->is($to)) {
            $this->error('Source and target are the same account.');

            return self::FAILURE;
        }

        $replace = (bool) $this->option('replace');

        if (! $replace && $accounts->hasConfiguration($to)) {
            $this->error("{$to->email} already has tracking data of its own. Nothing was moved.");
            $this->line('Run again with --replace to delete that data and move the data over it.');

            return self::FAILURE;
        }

        $moving = array_filter($accounts->counts($from));
        if ($moving === []) {
            $this->info("{$from->email} has no data to move.");

            return self::SUCCESS;
        }

        $this->line("Moving from {$from->email} to {$to->email}:");
        $this->table(['What', 'Rows'], $this->rows($moving));

        if ($replace && ($losing = array_filter($accounts->counts($to))) !== []) {
            unset($losing['settings']); // settings are replaced key by key, not deleted
            if ($losing !== []) {
                $this->warn("This DELETES the data {$to->email} has now:");
                $this->table(['What', 'Rows'], $this->rows($losing));
            }
        }

        if (! $this->option('force') && ! $this->confirm('Continue?')) {
            $this->line('Nothing was moved.');

            return self::SUCCESS;
        }

        $accounts->move($from, $to, $replace);

        $this->info("Done. {$to->email} now has the data; {$from->email} is empty.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<int, array{string, int}>
     */
    private function rows(array $counts): array
    {
        $labels = [
            'categories' => 'Tracked categories',
            'tag_filters' => 'Tag filters',
            'tracked_channels' => 'Tracked channels',
            'blacklist_rules' => 'Blacklist entries',
            'alert_rules' => 'Alerts',
            'streams' => 'Live streams',
            'history_events' => 'History events',
            'settings' => 'Account settings (filters, alert destinations)',
        ];

        $rows = [];
        foreach ($counts as $table => $count) {
            $rows[] = [$labels[$table] ?? $table, $count];
        }

        return $rows;
    }
}
