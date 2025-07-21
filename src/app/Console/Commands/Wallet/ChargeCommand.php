<?php

namespace App\Console\Commands\Wallet;

use App\Console\Command;
use App\Jobs\Wallet\ChargeJob;
use App\Jobs\Wallet\CheckJob;
use App\User;
use App\Wallet;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class ChargeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallet:charge {--topup : Only top-up wallets} {--dry-run} {wallet?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Charge wallets, and trigger a topup on charged wallets.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if ($wallet = $this->argument('wallet')) {
            // Find specified wallet by ID
            $wallet = $this->getWallet($wallet);

            if (!$wallet) {
                $this->error("Wallet not found.");
                return 1;
            }

            if (!$wallet->owner) {
                $this->error("Wallet's owner is deleted.");
                return 1;
            }

            $wallets = [$wallet];
        } elseif ($this->option('topup')) {
            // Find wallets that need to be topped up
            $wallets = Wallet::select('wallets.id')
                ->join('users', 'users.id', '=', 'wallets.user_id')
                ->join('wallet_settings', static function (JoinClause $join) {
                    $join->on('wallet_settings.wallet_id', '=', 'wallets.id')
                        ->where('wallet_settings.key', '=', 'mandate_balance');
                })
                ->whereNull('users.deleted_at')
                ->whereRaw('wallets.balance < (wallet_settings.value * 100)')
                ->whereNot('users.status', '&', User::STATUS_DEGRADED | User::STATUS_SUSPENDED)
                ->cursor();
        } else {
            // Get all wallets...
            $wallets = Wallet::select('wallets.id')
                ->join('users', 'users.id', '=', 'wallets.user_id')
                // exclude deleted accounts
                ->whereNull('users.deleted_at')
                // exclude wallets w/o entitlements (but not wallets with a negative balance)
                ->where(static function ($query) {
                    $query->where('balance', '<', 0)
                        ->orWhereExists(static function (Builder $query) {
                            $query->select(DB::raw(1))
                                ->from('entitlements')
                                ->whereColumn('entitlements.wallet_id', 'wallets.id');
                        });
                })
                ->cursor();
        }

        foreach ($wallets as $wallet) {
            if ($this->option('dry-run')) {
                $this->info($wallet->id);
            } else {
                if ($this->option('topup')) {
                    $this->info("Dispatching wallet charge for {$wallet->id}");
                    ChargeJob::dispatch($wallet->id);
                } else {
                    CheckJob::dispatch($wallet->id);
                }
            }
        }
    }
}
