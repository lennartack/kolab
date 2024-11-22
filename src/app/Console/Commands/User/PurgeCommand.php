<?php

namespace App\Console\Commands\User;

use App\Console\Command;
use App\User;
use Illuminate\Database\Eloquent\Builder;

class PurgeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:purge {--dry-run} {--min-age=2y} {--limit=} {--suspended}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete users that are degraded';


    private function parseAge($age)
    {
        if (preg_match('/^([0-9]+)([my])$/i', $age, $matches)) {
            $count = (int) $matches[1];
            $period = strtolower($matches[2]);
            $date = \Carbon\Carbon::now();

            if ($period == 'y') {
                $date->subYearsWithoutOverflow($count);
            } elseif ($period == 'm') {
                $date->subMonthsWithoutOverflow($count);
            }
            return $date;
        }
        return null;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $dry_run = $this->option('dry-run');
        $min_age = $this->option('min-age');
        $limit = $this->option('limit');
        $suspended = $this->option('suspended');

        if (!$dry_run) {
            $this->warn("THIS COMMAND WILL DELETE USERS, are you sure?");
            if (!$this->confirm("Are you sure you understand what's about to happen?")) {
                return 0;
            }
        }

        $date = $this->parseAge($min_age);
        if (!$date) {
            $this->error("Invalid --min-age.");
            return 1;
        } else {
            $this->info("The cutoff date is " . $date->format('Y-m-d H:i:s'));
        }

        $statusFilter = User::STATUS_DEGRADED;
        if ($suspended) {
            $statusFilter = User::STATUS_SUSPENDED;
        }

        // Find inactive users by checking:
        // * the account is degraded/suspended
        // * there is no active oauth token
        // * there is no recent policy_ratelimit entry for the user (so no email exchanges)
        // * there is no successful payment ever
        // * there is no 'award' transaction ever
        // * there is no 100% discount
        // * there is no recent roundcube login for the user
        $users = User::select('users.id', 'users.email')
            ->leftJoin('oauth_access_tokens', 'oauth_access_tokens.user_id', '=', 'users.id')
            ->leftJoin('roundcube_prod.users as rcusers', 'rcusers.username', '=', 'users.email')
            ->where('users.status', '&', $statusFilter)
            ->where('users.updated_at', '<=', $date)
            ->where('users.created_at', '<=', $date)
            ->whereNull('oauth_access_tokens.id')
            ->whereNotIn(
                'users.id',
                function ($query) {
                    $query->select('user_id')->from('wallets')
                        ->leftJoin('payments', 'wallets.id', '=', 'payments.wallet_id')
                        ->where('payments.status', '=', 'paid');
                }
            )
            ->whereNotIn(
                'users.id',
                function ($query) {
                    $query->select('user_id')->from('wallets')
                        ->leftJoin('discounts', 'wallets.discount_id', '=', 'discounts.id')
                        ->where('discounts.discount', '=', 100);
                }
            )
            ->whereNotIn(
                'users.id',
                function ($query) {
                    $query->select('user_id')->from('wallets')
                        ->leftJoin('transactions', 'wallets.id', '=', 'transactions.object_id')
                        ->where('transactions.type', '=', 'award');
                }
            )
            ->whereNotIn(
                'users.id',
                function ($query) use ($date) {
                    $query->select('user_id')->from('policy_ratelimit')
                        ->where('policy_ratelimit.created_at', '>=', $date);
                }
            )
            ->where(
                function (Builder $query) use ($date) {
                    $query->where('rcusers.last_login', '<=', $date)
                        ->orWhereNull('rcusers.username');
                }
            )
            ->orderBy('users.created_at');

        if ($limit > 0) {
            $users->limit($limit);
        }

        $count = 0;
        foreach ($users->cursor() as $user) {
            $count++;
            if ($dry_run) {
                $this->info("{$user->email}: will be deleted");
            } else {
                $this->info("Deleting {$user->email}");
                $user->delete();
            }
        }
        $this->info("A total of $count users will be deleted");
    }
}
