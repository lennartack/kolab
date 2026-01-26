<?php

namespace App\Console\Commands\Data\Stats;

use App\Http\Controllers\API\V4\Admin\StatsController;
use App\Transaction;
use App\User;
use App\Wallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CollectorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'data:stats:collector';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Collects statistical data about the system (for charts)';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->collectPayersCount();
    }

    /**
     * Collect current payers count
     */
    protected function collectPayersCount(): void
    {
        // A subquery to get the all wallets with a credit/award transaction
        $transactions = DB::table('transactions')
            ->selectRaw('distinct object_id as wallet_id')
            ->where('object_type', Wallet::class)
            ->where('amount', '>', 0)
            ->whereIn('type', [Transaction::WALLET_AWARD, Transaction::WALLET_CREDIT]);

        // A subquery to get users' wallets (by entitlement) - one record per user
        $wallets = DB::table('entitlements')
            ->selectRaw("min(wallet_id) as id, entitleable_id as user_id")
            ->where('entitleable_type', User::class)
            ->groupBy('entitleable_id');

        $discounts = DB::table('wallets')
            ->select('wallets.id as wallet_id', 'discounts.discount')
            ->leftJoin('discounts', 'discounts.id', '=', 'wallets.discount_id');

        // Count all non-degraded and non-deleted users that are payers
        $counts = DB::table('users')
            ->selectRaw('count(*) as total, users.tenant_id')
            ->joinSub($wallets, 'wallets', static function ($join) {
                $join->on('users.id', '=', 'wallets.user_id');
            })
            ->joinSub($transactions, 'transactions', static function ($join) {
                $join->on('wallets.id', '=', 'transactions.wallet_id');
            })
            ->leftJoinSub($discounts, 'discounts', static function ($join) {
                $join->on('discounts.wallet_id', '=', 'wallets.id');
            })
            ->whereRaw('(discounts.discount is null or discounts.discount < 100)')
            ->whereNull('users.deleted_at')
            ->whereNot('users.status', '&', User::STATUS_DEGRADED)
            ->whereNot('users.status', '&', User::STATUS_SUSPENDED)
            ->groupBy('users.tenant_id')
            ->havingRaw('count(*) > 0')
            ->get()
            ->each(static function ($record) {
                DB::table('stats')->insert([
                    'tenant_id' => $record->tenant_id,
                    'type' => StatsController::TYPE_PAYERS,
                    'value' => $record->total,
                ]);
            });
    }
}
